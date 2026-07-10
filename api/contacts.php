<?php
namespace UtsovAPI;
use PDO;
require(dirname(__FILE__).'/utils.php');
require(dirname(__FILE__).'/email-builder.php');
date_default_timezone_set('America/New_York');

$_post = json_decode(file_get_contents("php://input"));

// Guard: malformed or missing JSON body
if (!$_post) {
    echo json_encode(['err' => 'Invalid request', 'msg' => '']);
    exit;
}

$action = isset($_post->action) ? $_post->action : 'add';

switch($action) {
    case "add": addContact($_post); break;
    default:    addContact($_post);
}

function sanitizeHeader($value) {
    // Strip CR/LF to prevent email header injection
    return str_replace(["\r", "\n", "\r\n"], '', $value);
}

function addContact($post) {
    $return = ['err' => '', 'msg' => ''];
    try {
        $db = new PDO("sqlite:" . getDBPath("capture"));
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $ip = get_client_ip();

        // Input length limits prevent oversized DB writes
        $name    = mb_substr(isset($post->name)    ? (string)$post->name    : '', 0, 200);
        $email   = mb_substr(isset($post->email)   ? (string)$post->email   : '', 0, 254);
        $phone   = mb_substr(isset($post->phone)   ? (string)$post->phone   : '', 0, 50);
        $topic   = mb_substr(isset($post->topic)   ? (string)$post->topic   : 'general', 0, 100);
        $message = mb_substr(isset($post->message) ? (string)$post->message : '', 0, 5000);
        $date    = date("Y-m-d H:i:s");

        // Validate email format before storing
        $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';

        $db->exec("CREATE TABLE IF NOT EXISTS tb_contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date TEXT, name TEXT, email TEXT, phone TEXT,
            topic TEXT, message TEXT, ipaddress TEXT
        )");
        $stmt = $db->prepare("INSERT INTO tb_contacts(date,name,email,phone,topic,message,ipaddress)
            VALUES(:date,:name,:email,:phone,:topic,:msg,:ip)");
        $stmt->bindParam(':date', $date);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':phone', $phone);
        $stmt->bindParam(':topic', $topic);
        $stmt->bindParam(':msg', $message);
        $stmt->bindParam(':ip', $ip);
        $stmt->execute();

        // Pre-escape
        $cSafeName  = htmlspecialchars($name,    ENT_QUOTES, 'UTF-8');
        $cSafeEmail = htmlspecialchars($email,   ENT_QUOTES, 'UTF-8');
        $cSafePhone = htmlspecialchars($phone,   ENT_QUOTES, 'UTF-8');
        $cSafeTopic = htmlspecialchars(ucfirst($topic), ENT_QUOTES, 'UTF-8');
        $cSafeMsg   = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
        $cHeaders   = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: noreply@utsov.org\r\n";

        // Auto-reply to submitter — personalized PNG
        $mailHeaders = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: noreply@utsov.org\r\n";
        if ($email) {
            $cReplySubject = "We received your message - UTSOV 2026";
            $imgUrl = buildEmailImage(
                'MESSAGE RECEIVED', true,
                "Thank you, {$name}!",
                array("We have received your message about {$cSafeTopic}.", 'Our team will get back to you within 1-2 business days.'),
                null, null,
                array('In the meantime, visit our website for event details and registration.'),
                $email . '_contact'
            );
            if ($imgUrl) {
                $cReplyBody =
                    "<html><body style='margin:0;padding:20px 0;background-color:#0d0000;text-align:center;font-family:Arial,sans-serif;'>"
                  . "<img src='{$imgUrl}' width='600' alt='UTSOV 2026 Message Received' style='display:block;margin:0 auto;max-width:100%;border:0;'>"
                  . "<p style='color:#888;font-size:11px;margin:8px auto 0;max-width:600px;'>Message received from {$cSafeName} re: {$cSafeTopic}</p>"
                  . "</body></html>";
            } else {
                $cReplyBody =
                    "<html><body style='font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto'>"
                  . "<h2 style='color:#8B1A1A'>Thank you, {$cSafeName}!</h2>"
                  . "<p>We received your message about {$cSafeTopic}. We'll reply within 1-2 business days.</p>"
                  . "<p style='color:#8B1A1A'>-- The UTSOV Team</p></body></html>";
            }
            mail($email, sanitizeHeader($cReplySubject), $cReplyBody, $mailHeaders);
        }

        // Admin notification
        $subject = "UTSOV Website Inquiry - " . ucfirst(sanitizeHeader($topic)) . " from " . sanitizeHeader($name);
        $body = "<html><body style='font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto'>"
            . "<h2 style='color:#8B1A1A'>New Contact: {$cSafeName}</h2>"
            . "<table style='border-collapse:collapse;font-size:14px'>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Name:</b></td><td>{$cSafeName}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Email:</b></td><td>{$cSafeEmail}</td></tr>"
            . ($phone ? "<tr><td style='padding:4px 16px 4px 0'><b>Phone:</b></td><td>{$cSafePhone}</td></tr>" : '')
            . "<tr><td style='padding:4px 16px 4px 0'><b>Topic:</b></td><td>{$cSafeTopic}</td></tr>"
            . ($message ? "<tr><td style='padding:4px 16px 4px 0;vertical-align:top'><b>Message:</b></td><td>{$cSafeMsg}</td></tr>" : '')
            . "</table>"
            . "</body></html>";
        mail('utsov@utsov.org', sanitizeHeader($subject), $body, $mailHeaders);

        $return['msg'] = 'SUCCESS';
    } catch(PDOException $e) {
        $return['err'] = 'DB error';
        // Don't leak internal DB error details to the client
        $return['msg'] = 'An error occurred. Please try again.';
    }
    echo json_encode($return);
}
?>
