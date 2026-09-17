<?php
namespace UtsovAPI;
use PDO;
require(dirname(__FILE__).'/utils.php');
require(dirname(__FILE__).'/receipt-config.php');
date_default_timezone_set('America/New_York');

header('Content-Type: application/json');

// TEMPORARY diagnostic shutdown handler - catches any fatal PHP error and returns valid
// JSON instead of a broken/HTML response (which is what breaks response.json() on the
// client and shows as "Network error"). Also logs the real error for us to inspect. Remove
// once the flow is confirmed stable.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        @file_put_contents(
            dirname(__FILE__) . '/fatal_debug.log',
            date('c') . " " . json_encode($e) . "\n\n",
            FILE_APPEND
        );
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['err' => 'Server error while processing your receipt. Please try again.']);
    }
});

$action = isset($_POST['action']) ? (string)$_POST['action'] : (isset($_GET['action']) ? (string)$_GET['action'] : '');

switch ($action) {
    case 'extract':          extractReceipt();          break;
    case 'check_duplicate':  checkDuplicate();           break;
    case 'submit':           submitRegistration();       break;
    default:
        echo json_encode(['err' => 'Unknown action']);
}

// ─── helpers ──────────────────────────────────────────────────────────────────

function openDB() {
    $db = new PDO("sqlite:" . getDBPath("register"));
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA busy_timeout=5000");
    // Same base schema as checkout.php's openDB() - this endpoint writes into the
    // exact same tb_registrations table, just via a different code path.
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
    $cols = $db->query("PRAGMA table_info(tb_registrations)")->fetchAll(\PDO::FETCH_COLUMN, 1);
    $newCols = [
        'city'              => "ALTER TABLE tb_registrations ADD COLUMN city TEXT",
        'adults'            => "ALTER TABLE tb_registrations ADD COLUMN adults INTEGER",
        'kids_6to12'        => "ALTER TABLE tb_registrations ADD COLUMN kids_6to12 INTEGER DEFAULT 0",
        'children'          => "ALTER TABLE tb_registrations ADD COLUMN children INTEGER",
        'attendee_names'    => "ALTER TABLE tb_registrations ADD COLUMN attendee_names TEXT",
        'food_pref'         => "ALTER TABLE tb_registrations ADD COLUMN food_pref TEXT",
        'days_selection'    => "ALTER TABLE tb_registrations ADD COLUMN days_selection TEXT",
        'is_student'        => "ALTER TABLE tb_registrations ADD COLUMN is_student INTEGER DEFAULT 0",
        'email_updates'     => "ALTER TABLE tb_registrations ADD COLUMN email_updates INTEGER DEFAULT 1",
        'whatsapp_updates'  => "ALTER TABLE tb_registrations ADD COLUMN whatsapp_updates INTEGER DEFAULT 0",
        'heard_from'        => "ALTER TABLE tb_registrations ADD COLUMN heard_from TEXT",
        'payment_method'    => "ALTER TABLE tb_registrations ADD COLUMN payment_method TEXT",
        'payment_reference' => "ALTER TABLE tb_registrations ADD COLUMN payment_reference TEXT",
        'screenshot_path'   => "ALTER TABLE tb_registrations ADD COLUMN screenshot_path TEXT",
    ];
    foreach ($newCols as $col => $sql) {
        if (!in_array($col, $cols)) $db->exec($sql);
    }
    return $db;
}

function sanitizeHeader($value) {
    return str_replace(["\r", "\n", "\r\n"], '', $value);
}

/** Calls Groq's vision API (OpenAI-compatible chat completions) with the given image bytes
 *  + mime type. Returns the decoded JSON object the model returned, or null on any failure
 *  (network, non-2xx, unparsable). */
function groqExtract($imageBytes, $mimeType) {
    $prompt = <<<PROMPT
You are reading a screenshot of a peer-to-peer payment confirmation (Zelle, Venmo, Cash App, or similar). Extract exactly the following as strict JSON, with no markdown fences and no prose before or after:

{
  "is_valid_receipt": true or false (false if this image is not a payment confirmation screenshot at all),
  "payment_app": "zelle" | "venmo" | "cashapp" | "other" | null,
  "amount": number or null (numeric dollar amount only, no currency symbol),
  "sender_name": string or null (the name of the person who SENT the payment, as shown),
  "date": string or null (ISO 8601 date if determinable, else the raw text shown),
  "reference": string or null (confirmation/transaction/reference number if shown),
  "memo_text": string or null (any note/memo/"what's this for" text visible on the screenshot),
  "email_in_memo": string or null (an email address, ONLY if one literally appears in the memo text),
  "guest_count_in_memo": number or null (a number of guests/passes/people, ONLY if explicitly stated in the memo text)
}

Be conservative: if a field is not clearly legible or not present, use null rather than guessing. Do not infer values that are not visibly present in the image.
PROMPT;

    $dataUrl = 'data:' . $mimeType . ';base64,' . base64_encode($imageBytes);
    $payload = json_encode([
        'model' => GROQ_VISION_MODEL,
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => $prompt],
                ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
            ],
        ]],
        'temperature'     => 0.1,
        // Generous headroom - this model has a "thinking" mode and was observed burning
        // its token budget on reasoning before completing the JSON content for a harder-to-
        // read (camera photo vs. clean screenshot) image, which made Groq reject the whole
        // response as invalid JSON (HTTP 400, empty failed_generation).
        'max_tokens'      => 4096,
        'response_format' => ['type' => 'json_object'],
    ]);

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        // Same SSL-verification bypass checkout.php's PayPal calls already use on this host.
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . GROQ_API_KEY],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_errno($ch);
    $errMsg = curl_error($ch);
    curl_close($ch);

    // TEMPORARY debug log - remove once extraction is confirmed working reliably.
    @file_put_contents(
        dirname(__FILE__) . '/groq_debug.log',
        date('c') . " httpCode={$httpCode} curlErrno={$err} curlErr={$errMsg} bodySnippet=" . substr((string)$body, 0, 500) . "\n\n",
        FILE_APPEND
    );

    if ($err || $httpCode !== 200 || !$body) return null;

    $resp = json_decode($body);
    if (!isset($resp->choices[0]->message->content)) return null;

    $extracted = json_decode($resp->choices[0]->message->content, true);
    return is_array($extracted) ? $extracted : null;
}

/** Saves uploaded image bytes to the private receipts folder. Returns the relative
 *  path stored in the DB, or null on failure. */
function saveReceiptImage($imageBytes, $mimeType) {
    $ext = ($mimeType === 'image/png') ? 'png' : (($mimeType === 'image/webp') ? 'webp' : 'jpg');
    // random_bytes() is disabled on this host - uniqid(mt_rand(), true) is sufficient
    // here since this only needs to be unique, not cryptographically secure.
    $filename = date('Ymd_His') . '_' . substr(md5(uniqid((string)mt_rand(), true)), 0, 16) . '.' . $ext;
    $dir = dirname(__FILE__) . '/uploads/receipts';
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $path = $dir . '/' . $filename;
    if (file_put_contents($path, $imageBytes) === false) return null;
    return 'uploads/receipts/' . $filename;
}

// ─── extract action ────────────────────────────────────────────────────────────

function extractReceipt() {
    $return = ['err' => ''];

    if (!isset($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
        $return['err'] = 'No image received. Please try uploading again.';
        echo json_encode($return); return;
    }

    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    $mimeType = mime_content_type($_FILES['receipt']['tmp_name']);
    if (!in_array($mimeType, $allowedMimes)) {
        $return['err'] = 'Please upload a JPG, PNG, or WEBP image.';
        echo json_encode($return); return;
    }

    // 8MB cap - typical phone camera photos are well under this
    if ($_FILES['receipt']['size'] > 8 * 1024 * 1024) {
        $return['err'] = 'Image too large. Please upload a photo under 8MB.';
        echo json_encode($return); return;
    }

    $imageBytes = file_get_contents($_FILES['receipt']['tmp_name']);
    $extracted = groqExtract($imageBytes, $mimeType);

    if ($extracted === null) {
        $return['err'] = 'Could not read the receipt right now. Please try again in a moment.';
        echo json_encode($return); return;
    }
    if (empty($extracted['is_valid_receipt'])) {
        $return['err'] = "This doesn't look like a payment confirmation screenshot. Please upload the receipt from your Zelle/Venmo app.";
        echo json_encode($return); return;
    }
    if (empty($extracted['amount']) || empty($extracted['sender_name'])) {
        $return['err'] = "Couldn't clearly read the amount and sender name from this image. Please upload a clearer screenshot.";
        echo json_encode($return); return;
    }

    // Persist the image now (not only on final submit) so it's captured even if the
    // user abandons the flow before confirming - useful for admin follow-up either way.
    $screenshotPath = saveReceiptImage($imageBytes, $mimeType);

    $return['extracted'] = $extracted;
    $return['screenshotPath'] = $screenshotPath;
    echo json_encode($return);
}

// ─── duplicate check ───────────────────────────────────────────────────────────

function findExistingRegistration($db, $name, $email) {
    $conditions = [];
    $params = [];
    if (!empty($email)) {
        $conditions[] = "LOWER(email) = LOWER(:email)";
        $params[':email'] = $email;
    }
    if (!empty($name)) {
        // Strip a possible "Z - " receipt-flow marker off the stored name before comparing,
        // so re-submissions still match against previously-marked rows.
        $conditions[] = "LOWER(TRIM(CASE WHEN name LIKE 'Z - %' THEN SUBSTR(name, 5) ELSE name END)) = LOWER(TRIM(:name))";
        $params[':name'] = $name;
    }
    if (!$conditions) return null;

    $stmt = $db->prepare(
        "SELECT date, name, email, qty, total_amount, status, payment_method
         FROM tb_registrations WHERE (" . implode(' OR ', $conditions) . ")
         ORDER BY date DESC LIMIT 1"
    );
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && strpos($row['name'], 'Z - ') === 0) {
        $row['name'] = substr($row['name'], 4); // don't show the internal marker back to the user
    }
    return $row ?: null;
}

function checkDuplicate() {
    $return = ['err' => ''];
    $name  = isset($_POST['name'])  ? mb_substr((string)$_POST['name'], 0, 200)  : '';
    $email = isset($_POST['email']) ? mb_substr((string)$_POST['email'], 0, 254) : '';

    try {
        $db = openDB();
        $existing = findExistingRegistration($db, $name, $email);
        $return['existing'] = $existing;
    } catch (\PDOException $e) {
        $return['err'] = 'A server error occurred.';
    }
    echo json_encode($return);
}

// ─── submit action ─────────────────────────────────────────────────────────────

function submitRegistration() {
    $return = ['err' => ''];

    $name      = mb_substr(isset($_POST['name'])      ? (string)$_POST['name']      : '', 0, 200);
    $email     = mb_substr(isset($_POST['email'])     ? (string)$_POST['email']     : '', 0, 254);
    $email     = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    $amount    = filter_var(isset($_POST['amount'])   ? $_POST['amount']            : 0, FILTER_VALIDATE_FLOAT);
    $reference = mb_substr(isset($_POST['reference']) ? (string)$_POST['reference'] : '', 0, 100);
    $app       = mb_substr(isset($_POST['app'])       ? (string)$_POST['app']       : 'other', 0, 20);
    $screenshotPath = mb_substr(isset($_POST['screenshotPath']) ? (string)$_POST['screenshotPath'] : '', 0, 255);

    if (!$name)  { $return['err'] = 'Sender name is required.';  echo json_encode($return); return; }
    if (!$email) { $return['err'] = 'A valid email is required.'; echo json_encode($return); return; }
    if ($amount === false || $amount <= 0 || $amount > 5000) {
        $return['err'] = 'Invalid payment amount.';
        echo json_encode($return); return;
    }

    // All of the following are OPTIONAL, per the user's instruction - no validation beyond
    // clamping to a sane range. Blank/missing just means "not specified" and falls back to
    // the same defaults the old flow used.
    $adults    = filter_var(isset($_POST['adults'])  ? $_POST['adults']  : null, FILTER_VALIDATE_INT);
    $kids612   = filter_var(isset($_POST['kids612']) ? $_POST['kids612'] : null, FILTER_VALIDATE_INT);
    $kidsu6    = filter_var(isset($_POST['kidsu6'])  ? $_POST['kidsu6']  : null, FILTER_VALIDATE_INT);
    $adults    = ($adults === false || $adults === null)   ? 0 : max(0, min(20, $adults));
    $kids612   = ($kids612 === false || $kids612 === null) ? 0 : max(0, min(20, $kids612));
    $kidsu6    = ($kidsu6 === false || $kidsu6 === null)   ? 0 : max(0, min(20, $kidsu6));

    $allowedDays = ['both', 'saturday', 'sunday'];
    $days = isset($_POST['days']) && in_array($_POST['days'], $allowedDays) ? $_POST['days'] : 'both';

    $allowedFood = ['vegetarian', 'nonvegetarian', 'nodinner'];
    $food = isset($_POST['food']) && in_array($_POST['food'], $allowedFood) ? $_POST['food'] : 'nonvegetarian';

    $city       = mb_substr(isset($_POST['city']) ? (string)$_POST['city'] : '', 0, 100);
    $isStudent  = !empty($_POST['isStudent']) ? 1 : 0;

    // Total headcount = the breakdown, but if the uploader left everything blank, assume at
    // least the payer themselves (1 adult) rather than storing a zero-pass registration.
    $qty = $adults + $kids612 + $kidsu6;
    if ($qty < 1) { $adults = 1; $qty = 1; }

    $ip   = get_client_ip();
    $date = date("Y-m-d H:i:s");

    try {
        $db = openDB();

        // Server-side duplicate re-check - never trust the client-side check alone.
        $existing = findExistingRegistration($db, $name, $email);
        if ($existing) {
            $return['duplicate'] = $existing;
            echo json_encode($return); return;
        }

        // Rate-limit: max 5 submissions per IP per minute (same guard as checkout.php)
        $rStmt = $db->prepare(
            "SELECT COUNT(*) FROM tb_registrations
             WHERE ipaddress=:ip AND date > datetime('now','-1 minute')"
        );
        $rStmt->execute([':ip' => $ip]);
        if ((int)$rStmt->fetchColumn() >= 5) {
            $return['err'] = 'Too many requests. Please wait a moment and try again.';
            echo json_encode($return); return;
        }

        $unitPrice = round($amount / $qty, 2);

        $db->beginTransaction();
        $stmt = $db->prepare(
            "INSERT INTO tb_registrations
                (date, name, email, phone, city, qty, unit_price, total_amount,
                 adults, kids_6to12, children, attendee_names, food_pref, days_selection, is_student,
                 email_updates, whatsapp_updates, heard_from,
                 payment_method, payment_reference, screenshot_path, status, ipaddress)
             VALUES
                (:date, :name, :email, '', :city, :qty, :unit_price, :amount,
                 :adults, :kids612, :kidsu6, :attendee_names, :food, :days, :is_student,
                 1, 0, 'zelle_venmo_receipt',
                 :app, :reference, :screenshot, 'pending_review', :ip)"
        );
        $stmt->execute([
            ':date'            => $date,
            // "Z - " prefix marks this row as receipt-upload-originated (not PayPal) so it's
            // immediately obvious scanning the admin table - stripped back off if the admin
            // later needs the plain name for correspondence.
            ':name'            => 'Z - ' . $name,
            ':email'           => $email,
            ':city'            => $city,
            ':amount'          => round($amount, 2),
            ':qty'             => $qty,
            ':unit_price'      => $unitPrice,
            ':adults'          => $adults,
            ':kids612'         => $kids612,
            ':kidsu6'          => $kidsu6,
            ':food'            => $food,
            ':days'            => $days,
            ':is_student'      => $isStudent,
            ':attendee_names'  => json_encode([$name]),
            ':app'             => $app,
            ':reference'       => $reference,
            ':screenshot'      => $screenshotPath,
            ':ip'              => $ip,
        ]);
        $db->commit();

        // Notify admin - this record needs a manual glance regardless (payment method isn't
        // PayPal-verified), but the breakdown below is whatever the uploader actually entered,
        // not a blind default, whenever they filled it in.
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $safeApp = htmlspecialchars($app, ENT_QUOTES, 'UTF-8');
        $safeRef = htmlspecialchars($reference, ENT_QUOTES, 'UTF-8');
        $safeCity = htmlspecialchars($city, ENT_QUOTES, 'UTF-8');
        $adminSubject = "Pending Review: Zelle/Venmo Registration - " . sanitizeHeader($name);
        $adminBody = "<html><body style='font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto'>"
            . "<h2 style='color:#8B1A1A'>New Zelle/Venmo Receipt Submitted</h2>"
            . "<p style='color:#b00'><strong>Status: pending_review - please verify against the actual payment before marking paid.</strong></p>"
            . "<table style='border-collapse:collapse;font-size:14px'>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Name:</b></td><td>{$safeName}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Email:</b></td><td>{$safeEmail}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Amount:</b></td><td>\$" . number_format($amount, 2) . "</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Adults:</b></td><td>{$adults}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Kids (6-12):</b></td><td>{$kids612}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Children (under 6):</b></td><td>{$kidsu6}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Days:</b></td><td>{$days}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Food:</b></td><td>{$food}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Student:</b></td><td>" . ($isStudent ? 'Yes' : 'No') . "</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>City:</b></td><td>{$safeCity}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>App:</b></td><td>{$safeApp}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Reference:</b></td><td>{$safeRef}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Screenshot:</b></td><td>{$screenshotPath}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Date:</b></td><td>{$date}</td></tr>"
            . "</table>"
            . "<p>Review and confirm details in the admin portal.</p>"
            . "</body></html>";
        $mailHeaders = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: noreply@utsov.org\r\n";
        notifyAdmins(sanitizeHeader($adminSubject), $adminBody, $mailHeaders);

        $return['msg'] = 'SUCCESS';

    } catch (\PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        $return['err'] = 'A server error occurred. Please try again.';
    }

    echo json_encode($return);
}
?>
