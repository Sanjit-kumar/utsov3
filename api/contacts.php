<?php
namespace UtsovAPI;
use PDO;
require(dirname(__FILE__).'/utils.php');
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

        // Email header injection guard: strip CR/LF from subject components
        $subject = "UTSOV Website Inquiry — " . ucfirst(sanitizeHeader($topic)) . " from " . sanitizeHeader($name);
        $to      = 'utsov@utsov.org';
        $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: noreply@utsov.org\r\n";
        $body = "<html><body style='font-family:Arial,sans-serif;color:#333'>"
            . "<h2 style='color:#8B1A1A'>New Inquiry — UTSOV 2026</h2>"
            . "<p><strong>Topic:</strong> " . htmlspecialchars($topic, ENT_QUOTES, 'UTF-8') . "</p>"
            . "<p><strong>Name:</strong> " . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "</p>"
            . "<p><strong>Email:</strong> <a href='mailto:" . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . "</a></p>"
            . "<p><strong>Phone:</strong> " . htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') . "</p>"
            . "<p><strong>Message:</strong><br>" . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . "</p>"
            . "</body></html>";
        mail($to, $subject, $body, $headers);

        $return['msg'] = 'SUCCESS';
    } catch(PDOException $e) {
        $return['err'] = 'DB error';
        // Don't leak internal DB error details to the client
        $return['msg'] = 'An error occurred. Please try again.';
    }
    echo json_encode($return);
}
?>
