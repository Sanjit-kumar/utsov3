<?php
namespace UtsovAPI;
use PDO;
require(dirname(__FILE__).'/utils.php');
require(dirname(__FILE__).'/checkout-config.php');
require(dirname(__FILE__).'/email-builder.php');
date_default_timezone_set('America/New_York');

header('Content-Type: application/json');

$_post = json_decode(file_get_contents("php://input"));

if (!$_post) {
    echo json_encode(['err' => 'Invalid request', 'msg' => '']);
    exit;
}

$action = isset($_post->action) ? (string)$_post->action : '';

switch ($action) {
    case 'create':  createOrder($_post);  break;
    case 'capture': captureOrder($_post); break;
    default:
        echo json_encode(['err' => 'Unknown action', 'msg' => '']);
}

// ─── helpers ──────────────────────────────────────────────────────────────────

function sanitizeHeader($value) {
    return str_replace(["\r", "\n", "\r\n"], '', $value);
}

function ppGetToken() {
    $ch = curl_init(PAYPAL_API_BASE . '/v1/oauth2/token');
    curl_setopt_array($ch, array(
        CURLOPT_SSL_VERIFYPEER  => false,
        CURLOPT_SSL_VERIFYHOST  => false,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_POST            => true,
        CURLOPT_POSTFIELDS      => 'grant_type=client_credentials',
        CURLOPT_USERPWD         => PAYPAL_CLIENT_ID . ':' . PAYPAL_SECRET,
        CURLOPT_HTTPHEADER      => array('Accept: application/json', 'Accept-Language: en_US'),
        CURLOPT_TIMEOUT         => 20,
        CURLOPT_CONNECTTIMEOUT  => 10,
    ));
    $body = curl_exec($ch);
    $err  = curl_errno($ch);
    curl_close($ch);
    if ($err) return null;
    $data = json_decode($body);
    return isset($data->access_token) ? $data->access_token : null;
}

function ppCreateOrder($token, $qty, $total) {
    $totalStr = number_format($total, 2, '.', '');
    $desc     = 'UTSOV 2026 Day Pass x' . $qty;
    $payload  = json_encode(array(
        'intent'         => 'CAPTURE',
        'purchase_units' => array(array(
            'description' => $desc,
            'amount'      => array('currency_code' => 'USD', 'value' => $totalStr),
        )),
    ));
    $ch = curl_init(PAYPAL_API_BASE . '/v2/checkout/orders');
    curl_setopt_array($ch, array(
        CURLOPT_SSL_VERIFYPEER  => false,
        CURLOPT_SSL_VERIFYHOST  => false,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_POST            => true,
        CURLOPT_POSTFIELDS      => $payload,
        CURLOPT_HTTPHEADER      => array('Content-Type: application/json', 'Authorization: Bearer ' . $token),
        CURLOPT_TIMEOUT         => 20,
        CURLOPT_CONNECTTIMEOUT  => 10,
    ));
    $body = curl_exec($ch);
    $err  = curl_errno($ch);
    curl_close($ch);
    if ($err) return null;
    return json_decode($body);
}

function ppCaptureOrder($token, $orderId) {
    $ch = curl_init(PAYPAL_API_BASE . '/v2/checkout/orders/' . $orderId . '/capture');
    curl_setopt_array($ch, array(
        CURLOPT_SSL_VERIFYPEER  => false,
        CURLOPT_SSL_VERIFYHOST  => false,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_CUSTOMREQUEST   => 'POST',
        CURLOPT_POSTFIELDS      => '{}',
        CURLOPT_HTTPHEADER      => array('Content-Type: application/json', 'Authorization: Bearer ' . $token),
        CURLOPT_TIMEOUT         => 20,
        CURLOPT_CONNECTTIMEOUT  => 10,
    ));
    $body = curl_exec($ch);
    $err  = curl_errno($ch);
    curl_close($ch);
    if ($err) return null;
    return json_decode($body);
}

function openDB() {
    $db = new PDO("sqlite:" . getDBPath("register"));
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA busy_timeout=5000");
    $db->exec("CREATE TABLE IF NOT EXISTS tb_registrations (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        date              TEXT,
        updated_date      TEXT,
        name              TEXT,
        email             TEXT,
        phone             TEXT,
        city              TEXT,
        qty               INTEGER,
        unit_price        REAL,
        total_amount      REAL,
        adults            INTEGER,
        children          INTEGER,
        attendee_names    TEXT,
        food_pref         TEXT,
        days_selection    TEXT,
        is_student        INTEGER DEFAULT 0,
        email_updates     INTEGER DEFAULT 1,
        whatsapp_updates  INTEGER DEFAULT 0,
        heard_from        TEXT,
        paypal_order_id   TEXT UNIQUE,
        paypal_capture_id TEXT,
        status            TEXT,
        ipaddress         TEXT
    )");
    // Add new columns to existing tables that predate this schema
    $cols = $db->query("PRAGMA table_info(tb_registrations)")->fetchAll(\PDO::FETCH_COLUMN, 1);
    $newCols = [
        'city'             => "ALTER TABLE tb_registrations ADD COLUMN city TEXT",
        'adults'           => "ALTER TABLE tb_registrations ADD COLUMN adults INTEGER",
        'kids_6to12'       => "ALTER TABLE tb_registrations ADD COLUMN kids_6to12 INTEGER DEFAULT 0",
        'children'         => "ALTER TABLE tb_registrations ADD COLUMN children INTEGER",
        'attendee_names'   => "ALTER TABLE tb_registrations ADD COLUMN attendee_names TEXT",
        'food_pref'        => "ALTER TABLE tb_registrations ADD COLUMN food_pref TEXT",
        'days_selection'   => "ALTER TABLE tb_registrations ADD COLUMN days_selection TEXT",
        'is_student'       => "ALTER TABLE tb_registrations ADD COLUMN is_student INTEGER DEFAULT 0",
        'email_updates'    => "ALTER TABLE tb_registrations ADD COLUMN email_updates INTEGER DEFAULT 1",
        'whatsapp_updates' => "ALTER TABLE tb_registrations ADD COLUMN whatsapp_updates INTEGER DEFAULT 0",
        'heard_from'       => "ALTER TABLE tb_registrations ADD COLUMN heard_from TEXT",
    ];
    foreach ($newCols as $col => $sql) {
        if (!in_array($col, $cols)) $db->exec($sql);
    }
    return $db;
}

// ─── create action ─────────────────────────────────────────────────────────────

function createOrder($post) {
    $return = ['err' => '', 'msg' => ''];

    // Validate qty
    $qty = filter_var(
        isset($post->qty) ? $post->qty : 0,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 40]]
    );
    if ($qty === false) {
        $return['err'] = 'Invalid quantity. Please choose 1–40 passes.';
        echo json_encode($return); return;
    }

    // Validate buyer fields
    $name  = mb_substr(isset($post->name)  ? (string)$post->name  : '', 0, 200);
    $email = mb_substr(isset($post->email) ? (string)$post->email : '', 0, 254);
    $phone = mb_substr(isset($post->phone) ? (string)$post->phone : '', 0, 50);
    $city  = mb_substr(isset($post->city)  ? (string)$post->city  : '', 0, 100);
    $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';

    if (!$name)  { $return['err'] = 'Name is required.';          echo json_encode($return); return; }
    if (!$email) { $return['err'] = 'A valid email is required.'; echo json_encode($return); return; }

    // Additional registration fields
    $adults    = max(1, min(20, (int)(isset($post->adults)    ? $post->adults    : 1)));
    $kids6to12 = max(0, min(20, (int)(isset($post->kids6to12) ? $post->kids6to12 : 0)));
    $children  = max(0, min(20, (int)(isset($post->children)  ? $post->children  : 0)));

    $allowedDays = ['both', 'saturday', 'sunday'];
    $daysSelection = (isset($post->days) && in_array($post->days, $allowedDays)) ? $post->days : 'both';

    $allowedFood = ['vegetarian', 'nonvegetarian', 'nodinner'];
    $foodPref = (isset($post->food) && in_array($post->food, $allowedFood)) ? $post->food : 'nonvegetarian';

    $isStudent     = !empty($post->isStudent) ? 1 : 0;
    $emailUpdates  = !empty($post->emailUpdates) ? 1 : 0;
    $whatsappUpd   = !empty($post->whatsapp) ? 1 : 0;
    $heardFrom     = mb_substr(isset($post->heard) ? (string)$post->heard : '', 0, 100);

    // Attendee names: array from client, sanitize each entry
    $attendeeNamesRaw = isset($post->attendeeNames) && is_array($post->attendeeNames)
        ? $post->attendeeNames : [$name];
    $attendeeNames = array_map(function($n){ return mb_substr((string)$n, 0, 200); }, $attendeeNamesRaw);
    $attendeeNamesJson = json_encode(array_values($attendeeNames));

    // Validate amount sent from cart (min $40, max $5000 safety cap)
    $amount = filter_var(
        isset($post->amount) ? $post->amount : 0,
        FILTER_VALIDATE_FLOAT
    );
    if ($amount === false || $amount < 40 || $amount > 5000) {
        $return['err'] = 'Invalid payment amount. Please return to cart.';
        echo json_encode($return); return;
    }
    $total = round($amount, 2);
    $ip    = get_client_ip();
    $date  = date("Y-m-d H:i:s");

    try {
        $db = openDB();

        // Rate-limit: max 5 creates per IP per minute
        $rStmt = $db->prepare(
            "SELECT COUNT(*) FROM tb_registrations
             WHERE ipaddress=:ip AND date > datetime('now','-1 minute')"
        );
        $rStmt->execute([':ip' => $ip]);
        if ((int)$rStmt->fetchColumn() >= 5) {
            $return['err'] = 'Too many requests. Please wait a moment and try again.';
            echo json_encode($return); return;
        }

        // Get PayPal token and create order
        $token = ppGetToken();
        if (!$token) { $return['err'] = 'Payment service unavailable. Please try again.'; echo json_encode($return); return; }

        $ppOrder = ppCreateOrder($token, $qty, $total);
        if (!$ppOrder || !isset($ppOrder->id)) {
            $return['err'] = 'Could not create payment order. Please try again.';
            echo json_encode($return); return;
        }

        $db->beginTransaction();
        $stmt = $db->prepare(
            "INSERT INTO tb_registrations
                (date, name, email, phone, city, qty, unit_price, total_amount,
                 adults, kids_6to12, children, attendee_names, food_pref, days_selection, is_student,
                 email_updates, whatsapp_updates, heard_from,
                 paypal_order_id, status, ipaddress)
             VALUES
                (:date, :name, :email, :phone, :city, :qty, :unit_price, :total,
                 :adults, :kids6to12, :children, :attendee_names, :food_pref, :days, :is_student,
                 :email_updates, :whatsapp, :heard,
                 :ppid, 'created', :ip)"
        );
        $stmt->execute([
            ':date'           => $date,
            ':name'           => $name,
            ':email'          => $email,
            ':phone'          => $phone,
            ':city'           => $city,
            ':qty'            => $qty,
            ':unit_price'     => $total / $qty,
            ':total'          => $total,
            ':adults'         => $adults,
            ':kids6to12'      => $kids6to12,
            ':children'       => $children,
            ':attendee_names' => $attendeeNamesJson,
            ':food_pref'      => $foodPref,
            ':days'           => $daysSelection,
            ':is_student'     => $isStudent,
            ':email_updates'  => $emailUpdates,
            ':whatsapp'       => $whatsappUpd,
            ':heard'          => $heardFrom,
            ':ppid'           => $ppOrder->id,
            ':ip'             => $ip,
        ]);
        $db->commit();

        $return['paypalOrderId'] = $ppOrder->id;
        $return['total']         = $total;

    } catch (\PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        $return['err'] = 'A server error occurred. Please try again.';
    }

    echo json_encode($return);
}

// ─── capture action ────────────────────────────────────────────────────────────

function captureOrder($post) {
    $return = ['err' => '', 'msg' => ''];

    $orderId = mb_substr(isset($post->paypalOrderId) ? (string)$post->paypalOrderId : '', 0, 100);
    if (!$orderId) { $return['err'] = 'Missing order ID.'; echo json_encode($return); return; }

    try {
        $db = openDB();

        // Look up the row
        $sel = $db->prepare("SELECT * FROM tb_registrations WHERE paypal_order_id=:id");
        $sel->execute([':id' => $orderId]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);

        if (!$row) { $return['err'] = 'Order not found.'; echo json_encode($return); return; }

        // Idempotency — already paid, return success immediately
        if ($row['status'] === 'paid') {
            $return['msg'] = 'SUCCESS';
            echo json_encode($return); return;
        }

        $token = ppGetToken();
        if (!$token) { $return['err'] = 'Payment service unavailable. Please try again.'; echo json_encode($return); return; }

        $capture = ppCaptureOrder($token, $orderId);
        if (!$capture || !isset($capture->status)) {
            $return['err'] = 'Capture failed. Please contact support.';
            echo json_encode($return); return;
        }

        if ($capture->status !== 'COMPLETED') {
            $return['err'] = 'Payment not completed. Status: ' . htmlspecialchars($capture->status, ENT_QUOTES, 'UTF-8');
            echo json_encode($return); return;
        }

        // Verify captured amount matches stored total
        $capturedAmount = 0;
        if (isset($capture->purchase_units[0]->payments->captures[0]->amount->value)) {
            $capturedAmount = (float)$capture->purchase_units[0]->payments->captures[0]->amount->value;
        }
        if (abs($capturedAmount - (float)$row['total_amount']) > 0.01) {
            $return['err'] = 'Payment amount mismatch. Please contact support.';
            echo json_encode($return); return;
        }

        $captureId   = $capture->purchase_units[0]->payments->captures[0]->id;
        $updatedDate = date("Y-m-d H:i:s");

        $db->beginTransaction();
        $upd = $db->prepare(
            "UPDATE tb_registrations
             SET status='paid', paypal_capture_id=:cid, updated_date=:ud
             WHERE paypal_order_id=:oid"
        );
        $upd->execute([':cid' => $captureId, ':ud' => $updatedDate, ':oid' => $orderId]);
        $db->commit();

        // Send confirmation email to buyer
        $qty   = (int)$row['qty'];
        $total = number_format((float)$row['total_amount'], 2);
        $name  = $row['name'];
        $email = $row['email'];

        $daysMap    = ['both' => 'Both Days (Oct 17 &amp; 18)', 'saturday' => 'Saturday, Oct 17', 'sunday' => 'Sunday, Oct 18'];
        $foodMap    = ['vegetarian' => 'Vegetarian', 'nonvegetarian' => 'Non-Vegetarian', 'nodinner' => 'No Dinner'];
        $daysDisp   = isset($daysMap[$row['days_selection']]) ? $daysMap[$row['days_selection']] : 'Both Days';
        $foodDisp   = isset($foodMap[$row['food_pref']])       ? $foodMap[$row['food_pref']]       : 'Vegetarian';
        $adultsDisp = isset($row['adults'])   ? (int)$row['adults']   : $qty;
        $childDisp  = isset($row['children']) ? (int)$row['children'] : 0;
        $studentNote = !empty($row['is_student']) ? "<tr><td style='padding:4px 12px 4px 0'><strong>Pass Type:</strong></td><td>Student (bring valid student ID)</td></tr>" : '';

        // Format attendee names list
        $namesArr  = json_decode($row['attendee_names'], true);
        $namesHtml = '';
        if (is_array($namesArr) && count($namesArr) > 1) {
            $namesHtml = "<tr><td style='padding:4px 12px 4px 0;vertical-align:top'><strong>Attendees:</strong></td><td>"
                . implode('<br>', array_map(function($n){ return htmlspecialchars($n, ENT_QUOTES, 'UTF-8'); }, $namesArr))
                . "</td></tr>";
        }

        // Pre-escape all user data for HTML output
        $safeName   = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeEmail  = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $safeOrder  = htmlspecialchars($orderId, ENT_QUOTES, 'UTF-8');
        $safeCap    = htmlspecialchars($captureId, ENT_QUOTES, 'UTF-8');
        $safePhone  = htmlspecialchars($row['phone'], ENT_QUOTES, 'UTF-8');
        $safeCity   = !empty($row['city']) ? htmlspecialchars($row['city'], ENT_QUOTES, 'UTF-8') : '';
        $safeHeard  = htmlspecialchars(isset($row['heard_from']) ? $row['heard_from'] : '', ENT_QUOTES, 'UTF-8');
        $safeIp     = htmlspecialchars($row['ipaddress'], ENT_QUOTES, 'UTF-8');
        $passWord   = $qty > 1 ? 'passes' : 'pass';
        $kids6to12  = (int)(isset($row['kids_6to12']) ? $row['kids_6to12'] : 0);
        $guestLine  = $adultsDisp . ' adult' . ($adultsDisp != 1 ? 's' : '')
                    . ($childDisp > 0 ? ' + ' . $childDisp . ' child' . ($childDisp > 1 ? 'ren' : '') : '');

        // Buyer confirmation email — generates personalized PNG matching the preview design
        $buyerSubject = "Your UTSOV 2026 passes are confirmed!";
        $mailHeaders  = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: noreply@utsov.org\r\n";

        $tRows = array(
            array('Passes',          imgText("UTSOV 2026 x {$qty} {$passWord}"), false, false, false),
            array('Days',            imgText($daysDisp),                          true,  false, false),
            array('Adults',          imgText($guestLine),                         false, false, false),
            array('Meal Preference', imgText($foodDisp),                          true,  false, false),
        );
        if (!empty($row['is_student'])) {
            $tRows[] = array('Pass Type', 'Student (bring student ID)', false, false, false);
        }
        $tRows[] = array('Total Paid', "\${$total}", true, true,  false);
        $tRows[] = array('Order ID',   $orderId,     false, false, true);

        $imgUrl = buildEmailImage(
            'BOOKING CONFIRMED', false,
            "You're going to UTSOV 2026!",
            array("Dear {$name},", "Your payment of \${$total} has been received.", "Your {$qty} {$passWord} are confirmed!"),
            'BOOKING DETAILS',
            $tRows,
            array('Full schedule & venue details coming closer to', 'October 17-18, New Jersey. See you there!'),
            $orderId
        );

        if ($imgUrl) {
            $buyerBody =
                "<html><body style='margin:0;padding:20px 0;background-color:#0d0000;text-align:center;font-family:Arial,sans-serif;'>"
              . "<img src='{$imgUrl}' width='600' alt='UTSOV 2026 Booking Confirmed' style='display:block;margin:0 auto;max-width:100%;border:0;'>"
              . "<p style='color:#888;font-size:11px;margin:8px auto 0;max-width:600px;'>"
              . "Booking confirmed for {$safeName} | \${$total} | Order: {$safeOrder}"
              . "</p></body></html>";
        } else {
            // Fallback plain HTML if GD unavailable on server
            $buyerBody =
                "<html><body style='font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto'>"
              . "<h2 style='color:#8B1A1A'>You're going to UTSOV 2026!</h2>"
              . "<p>Hi {$safeName},</p><p>Your payment of <b>\${$total}</b> has been received.</p>"
              . "<table style='border-collapse:collapse;font-size:14px'>"
              . "<tr><td style='padding:4px 16px 4px 0'><b>Passes:</b></td><td>UTSOV 2026 x {$qty} {$passWord}</td></tr>"
              . "<tr><td style='padding:4px 16px 4px 0'><b>Days:</b></td><td>{$daysDisp}</td></tr>"
              . "<tr><td style='padding:4px 16px 4px 0'><b>Adults:</b></td><td>{$guestLine}</td></tr>"
              . "<tr><td style='padding:4px 16px 4px 0'><b>Meal:</b></td><td>{$foodDisp}</td></tr>"
              . "<tr><td style='padding:4px 16px 4px 0'><b>Total:</b></td><td>\${$total}</td></tr>"
              . "<tr><td style='padding:4px 16px 4px 0'><b>Order ID:</b></td><td>{$safeOrder}</td></tr>"
              . "</table>"
              . "<p style='color:#8B1A1A'>-- The UTSOV Team</p></body></html>";
        }
        mail($email, sanitizeHeader($buyerSubject), $buyerBody, $mailHeaders);

        // Admin notification
        $adminSubject = "New UTSOV 2026 Registration - " . sanitizeHeader($name);
        $namesDisp = (!empty($namesArr) && count($namesArr) > 1) ? htmlspecialchars(implode(', ', $namesArr), ENT_QUOTES, 'UTF-8') : '';
        $adminBody = "<html><body style='font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto'>"
            . "<h2 style='color:#8B1A1A'>New Registration: {$safeName}</h2>"
            . "<table style='border-collapse:collapse;font-size:14px'>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Name:</b></td><td>{$safeName}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Email:</b></td><td>{$safeEmail}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Phone:</b></td><td>{$safePhone}</td></tr>"
            . ($safeCity ? "<tr><td style='padding:4px 16px 4px 0'><b>City:</b></td><td>{$safeCity}</td></tr>" : '')
            . "<tr><td style='padding:4px 16px 4px 0'><b>Passes:</b></td><td>{$qty} {$passWord}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Days:</b></td><td>{$daysDisp}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Adults (12+):</b></td><td>{$adultsDisp}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Kids (6-12):</b></td><td>{$kids6to12}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Children (U6):</b></td><td>{$childDisp}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Meal:</b></td><td>{$foodDisp}</td></tr>"
            . ($namesDisp ? "<tr><td style='padding:4px 16px 4px 0'><b>Attendees:</b></td><td>{$namesDisp}</td></tr>" : '')
            . (!empty($row['is_student']) ? "<tr><td style='padding:4px 16px 4px 0'><b>Student Pass:</b></td><td>Yes</td></tr>" : '')
            . "<tr><td style='padding:4px 16px 4px 0'><b>Total:</b></td><td>\${$total}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>PayPal Order ID:</b></td><td>{$safeOrder}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Capture ID:</b></td><td>{$safeCap}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Heard From:</b></td><td>{$safeHeard}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>IP:</b></td><td>{$safeIp}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Date:</b></td><td>{$updatedDate}</td></tr>"
            . "</table>"
            . "</body></html>";
        mail('utsov@utsov.org', sanitizeHeader($adminSubject), $adminBody, $mailHeaders);

        $return['msg'] = 'SUCCESS';

    } catch (\PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        $return['err'] = 'A server error occurred. Please contact support.';
    }

    echo json_encode($return);
}
?>
