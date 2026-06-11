<?php
namespace UtsovAPI;
use PDO;
require(dirname(__FILE__).'/utils.php');
date_default_timezone_set('America/New_York');

$_post = json_decode(file_get_contents("php://input"));

if (!$_post) {
    echo json_encode(['err' => 'Invalid request', 'msg' => '']);
    exit;
}

$action = isset($_post->action) ? $_post->action : 'add';
switch($action) {
    case "add": addVol($_post); break;
    default:    addVol($_post);
}

function sanitizeHeader($value) {
    return str_replace(["\r", "\n", "\r\n"], '', $value);
}

function addVol($post) {
    $return = ['err' => '', 'msg' => ''];
    try {
        $db = new PDO("sqlite:" . getDBPath("volunteer"));
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $name         = mb_substr(isset($post->volname)  ? (string)$post->volname  : '', 0, 200);
        $email        = mb_substr(isset($post->volemail) ? (string)$post->volemail : '', 0, 254);
        $phone        = mb_substr(isset($post->volphone) ? (string)$post->volphone : '', 0, 50);
        $message      = mb_substr(isset($post->volmsg)   ? (string)$post->volmsg   : '', 0, 5000);
        $address1     = mb_substr(isset($post->voladd1)  ? (string)$post->voladd1  : '', 0, 200);
        $address2     = mb_substr(isset($post->voladd2)  ? (string)$post->voladd2  : '', 0, 200);
        $city         = mb_substr(isset($post->volcity)  ? (string)$post->volcity  : '', 0, 100);
        $state        = mb_substr(isset($post->volstate) ? (string)$post->volstate : '', 0, 50);
        $zip          = mb_substr(isset($post->volzip)   ? (string)$post->volzip   : '', 0, 20);
        $memberrequest = isset($post->addMember) ? (bool)$post->addMember : false;
        $date         = date("Ymd:His");
        $ipaddress    = get_client_ip();

        $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';

        $db->exec("CREATE TABLE IF NOT EXISTS tb_volunteers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date TEXT, name TEXT, address1 TEXT, address2 TEXT,
            city TEXT, state TEXT, zip TEXT, phone TEXT, email TEXT,
            message TEXT, ipaddress TEXT, memberrequest INTEGER
        )");

        $stmt = $db->prepare("INSERT INTO tb_volunteers
            (date,name,address1,address2,city,state,zip,phone,email,message,ipaddress,memberrequest)
            VALUES(:date,:name,:add1,:add2,:city,:state,:zip,:phone,:email,:msg,:ip,:memberrequest)");
        $stmt->bindParam(':date',          $date);
        $stmt->bindParam(':name',          $name);
        $stmt->bindParam(':add1',          $address1);
        $stmt->bindParam(':add2',          $address2);
        $stmt->bindParam(':city',          $city);
        $stmt->bindParam(':state',         $state);
        $stmt->bindParam(':zip',           $zip);
        $stmt->bindParam(':phone',         $phone);
        $stmt->bindParam(':email',         $email);
        $stmt->bindParam(':msg',           $message);
        $stmt->bindParam(':ip',            $ipaddress);
        $stmt->bindParam(':memberrequest', $memberrequest);
        $stmt->execute();

        $subject = "UTSOV Volunteer Sign-Up — " . sanitizeHeader($name);
        $to      = 'utsov@utsov.org';
        $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: noreply@utsov.org\r\n";
        $body = "<html><body style='font-family:Arial,sans-serif;color:#333'>"
            . "<h2 style='color:#8B1A1A'>New Volunteer Sign-Up — UTSOV 2026</h2>"
            . "<p><strong>Name:</strong> " . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "</p>"
            . "<p><strong>Email:</strong> <a href='mailto:" . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . "</a></p>"
            . "<p><strong>Phone:</strong> " . htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') . "</p>"
            . "<p><strong>Details:</strong><br>" . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . "</p>"
            . "</body></html>";
        mail($to, $subject, $body, $headers);

        $return['msg'] = 'SUCCESS';
    } catch(PDOException $e) {
        $return['err'] = 'DB error';
        $return['msg'] = 'An error occurred. Please try again.';
    }
    echo json_encode($return);
}
?>
