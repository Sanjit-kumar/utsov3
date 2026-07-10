<?php
namespace UtsovAPI;
use PDO;
require(dirname(__FILE__).'/utils.php');
require(dirname(__FILE__).'/checkout-config.php');
require(dirname(__FILE__).'/email-builder.php');
date_default_timezone_set('America/New_York');

header('Content-Type: application/json');

$_post = json_decode(file_get_contents("php://input"));
if (!$_post) { echo json_encode(['err' => 'Invalid request', 'msg' => '']); exit; }

$action = isset($_post->action) ? (string)$_post->action : '';
switch ($action) {
    case 'create':  createDonation($_post);  break;
    case 'capture': captureDonation($_post); break;
    default: echo json_encode(['err' => 'Unknown action', 'msg' => '']);
}

function sanitizeHeader($value) {
    return str_replace(["\r", "\n", "\r\n"], '', $value);
}

function curlSslOpts() {
    return PAYPAL_MODE === 'sandbox'
        ? [CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false]
        : [];
}

function ppGetToken() {
    $ch = curl_init(PAYPAL_API_BASE . '/v1/oauth2/token');
    curl_setopt_array($ch, curlSslOpts() + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_USERPWD        => PAYPAL_CLIENT_ID . ':' . PAYPAL_SECRET,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Accept-Language: en_US'],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($body);
    return isset($data->access_token) ? $data->access_token : null;
}

function ppCreateDonationOrder($token, $amount) {
    $amountStr = number_format($amount, 2, '.', '');
    $payload   = json_encode([
        'intent'         => 'CAPTURE',
        'purchase_units' => [[
            'description' => 'UTSOV 2026 Donation',
            'amount'      => ['currency_code' => 'USD', 'value' => $amountStr],
        ]],
    ]);
    $ch = curl_init(PAYPAL_API_BASE . '/v2/checkout/orders');
    curl_setopt_array($ch, curlSslOpts() + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return json_decode($body);
}

function ppCaptureOrder($token, $orderId) {
    $ch = curl_init(PAYPAL_API_BASE . '/v2/checkout/orders/' . $orderId . '/capture');
    curl_setopt_array($ch, curlSslOpts() + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => '{}',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return json_decode($body);
}

function openDonateDB() {
    $db = new PDO("sqlite:" . getDBPath("donation"));
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA busy_timeout=5000");
    $db->exec("CREATE TABLE IF NOT EXISTS tb_donations (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        date              TEXT,
        updated_date      TEXT,
        name              TEXT,
        email             TEXT,
        amount            REAL,
        paypal_order_id   TEXT,
        paypal_capture_id TEXT,
        status            TEXT,
        ipaddress         TEXT
    )");
    // Add columns that may be missing from older schema
    $cols = $db->query("PRAGMA table_info(tb_donations)")->fetchAll(\PDO::FETCH_COLUMN, 1);
    $newCols = array(
        'updated_date'      => "ALTER TABLE tb_donations ADD COLUMN updated_date TEXT",
        'ipaddress'         => "ALTER TABLE tb_donations ADD COLUMN ipaddress TEXT",
        'paypal_capture_id' => "ALTER TABLE tb_donations ADD COLUMN paypal_capture_id TEXT",
        'paypal_order_id'   => "ALTER TABLE tb_donations ADD COLUMN paypal_order_id TEXT",
        'status'            => "ALTER TABLE tb_donations ADD COLUMN status TEXT",
    );
    foreach ($newCols as $col => $sql) {
        if (!in_array($col, $cols)) $db->exec($sql);
    }
    return $db;
}

function createDonation($post) {
    $return = ['err' => '', 'msg' => ''];

    $name   = mb_substr(isset($post->name)  ? (string)$post->name  : '', 0, 200);
    $email  = mb_substr(isset($post->email) ? (string)$post->email : '', 0, 254);
    $email  = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    $amount = filter_var(isset($post->amount) ? $post->amount : 0, FILTER_VALIDATE_FLOAT);

    if (!$name)  { $return['err'] = 'Please enter your name.';          echo json_encode($return); return; }
    if (!$email) { $return['err'] = 'Please enter a valid email.';      echo json_encode($return); return; }
    if ($amount === false || $amount < 1 || $amount > 50000) {
        $return['err'] = 'Please enter a valid donation amount (minimum $1).';
        echo json_encode($return); return;
    }

    $amount = round($amount, 2);
    $ip     = get_client_ip();
    $date   = date("Y-m-d H:i:s");

    try {
        $db = openDonateDB();

        $rStmt = $db->prepare(
            "SELECT COUNT(*) FROM tb_donations WHERE ipaddress=:ip AND date > datetime('now','-1 minute')"
        );
        $rStmt->execute([':ip' => $ip]);
        if ((int)$rStmt->fetchColumn() >= 5) {
            $return['err'] = 'Too many requests. Please wait a moment.';
            echo json_encode($return); return;
        }

        $token = ppGetToken();
        if (!$token) { $return['err'] = 'Payment service unavailable. Please try again.'; echo json_encode($return); return; }

        $ppOrder = ppCreateDonationOrder($token, $amount);
        if (!$ppOrder || !isset($ppOrder->id)) {
            $return['err'] = 'Could not create donation order. Please try again.';
            echo json_encode($return); return;
        }

        $db->beginTransaction();
        $stmt = $db->prepare(
            "INSERT INTO tb_donations (date, name, email, amount, payment, paypal_order_id, status, ipaddress)
             VALUES (:date, :name, :email, :amount, 'paypal', :ppid, 'created', :ip)"
        );
        $stmt->execute(array(
            ':date'   => $date,
            ':name'   => $name,
            ':email'  => $email,
            ':amount' => $amount,
            ':ppid'   => $ppOrder->id,
            ':ip'     => $ip,
        ));
        $db->commit();

        $return['paypalOrderId'] = $ppOrder->id;
        $return['amount']        = $amount;

    } catch (\PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        $return['err'] = 'A server error occurred. Please try again.';
    }

    echo json_encode($return);
}

function captureDonation($post) {
    $return = ['err' => '', 'msg' => ''];

    $orderId = mb_substr(isset($post->paypalOrderId) ? (string)$post->paypalOrderId : '', 0, 100);
    if (!$orderId) { $return['err'] = 'Missing order ID.'; echo json_encode($return); return; }

    try {
        $db = openDonateDB();

        $sel = $db->prepare("SELECT * FROM tb_donations WHERE paypal_order_id=:id");
        $sel->execute([':id' => $orderId]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);

        if (!$row)                       { $return['err'] = 'Donation record not found.'; echo json_encode($return); return; }
        if ($row['status'] === 'paid')   { $return['msg'] = 'SUCCESS'; echo json_encode($return); return; }

        $token = ppGetToken();
        if (!$token) { $return['err'] = 'Payment service unavailable.'; echo json_encode($return); return; }

        $capture = ppCaptureOrder($token, $orderId);
        if (!$capture || !isset($capture->status)) {
            $return['err'] = 'Capture failed. Please contact support.';
            echo json_encode($return); return;
        }
        if ($capture->status !== 'COMPLETED') {
            $return['err'] = 'Payment not completed. Status: ' . htmlspecialchars($capture->status, ENT_QUOTES, 'UTF-8');
            echo json_encode($return); return;
        }

        $captureId   = $capture->purchase_units[0]->payments->captures[0]->id;
        $updatedDate = date("Y-m-d H:i:s");

        $db->beginTransaction();
        $upd = $db->prepare(
            "UPDATE tb_donations SET status='paid', paypal_capture_id=:cid, updated_date=:ud WHERE paypal_order_id=:oid"
        );
        $upd->execute([':cid' => $captureId, ':ud' => $updatedDate, ':oid' => $orderId]);
        $db->commit();

        $name   = $row['name'];
        $email  = $row['email'];
        $amount = number_format((float)$row['amount'], 2);

        // Pre-escape
        $dSafeName  = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $dSafeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $dSafeOrder = htmlspecialchars($orderId, ENT_QUOTES, 'UTF-8');
        $dSafeCap   = htmlspecialchars($captureId, ENT_QUOTES, 'UTF-8');
        $dSafeIp    = htmlspecialchars($row['ipaddress'], ENT_QUOTES, 'UTF-8');

        // Donor confirmation email — personalized PNG
        $donorSubject = "Thank you for supporting UTSOV 2026!";
        $mailHeaders  = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: noreply@utsov.org\r\n";

        $imgUrl = buildEmailImage(
            'DONATION CONFIRMED', false,
            'Thank you for supporting UTSOV 2026!',
            array("Dear {$name},", "Your generous donation of \${$amount} has been received.", 'Your support means everything to our community.'),
            'DONATION DETAILS',
            array(
                array('Amount',    "\${$amount}",  false, true,  false),
                array('Date',      $updatedDate,    true,  false, false),
                array('Reference', $orderId,        false, false, true),
            ),
            array('Please keep this email for your records.', 'Every donation helps bring our community together.'),
            $orderId
        );

        if ($imgUrl) {
            $donorBody =
                "<html><body style='margin:0;padding:20px 0;background-color:#0d0000;text-align:center;font-family:Arial,sans-serif;'>"
              . "<img src='{$imgUrl}' width='600' alt='UTSOV 2026 Donation Confirmed' style='display:block;margin:0 auto;max-width:100%;border:0;'>"
              . "<p style='color:#888;font-size:11px;margin:8px auto 0;max-width:600px;'>"
              . "Donation confirmed: {$dSafeName} | \${$amount} | Ref: {$dSafeOrder}"
              . "</p></body></html>";
        } else {
            $donorBody =
                "<html><body style='font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto'>"
              . "<h2 style='color:#8B1A1A'>Thank you for your donation!</h2>"
              . "<p>Hi {$dSafeName},</p>"
              . "<p>Your donation of <b>\${$amount}</b> has been received.</p>"
              . "<p style='color:#8B1A1A'>-- The UTSOV Team</p></body></html>";
        }
        mail($email, sanitizeHeader($donorSubject), $donorBody, $mailHeaders);

        // Admin notification
        $adminSubject = "New UTSOV Donation - " . sanitizeHeader($name) . " - \$$amount";
        $adminBody = "<html><body style='font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto'>"
            . "<h2 style='color:#8B1A1A'>New Donation: {$dSafeName}</h2>"
            . "<table style='border-collapse:collapse;font-size:14px'>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Name:</b></td><td>{$dSafeName}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Email:</b></td><td>{$dSafeEmail}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Amount:</b></td><td>\${$amount}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>PayPal Order ID:</b></td><td>{$dSafeOrder}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Capture ID:</b></td><td>{$dSafeCap}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Date:</b></td><td>{$updatedDate}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>IP:</b></td><td>{$dSafeIp}</td></tr>"
            . "</table>"
            . "</body></html>";
        mail('utsov@utsov.org', sanitizeHeader($adminSubject), $adminBody, $mailHeaders);

        $return['msg'] = 'SUCCESS';

    } catch (\PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        $return['err'] = 'A server error occurred.';
    }

    echo json_encode($return);
}
?>
