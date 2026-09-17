<?php
namespace UtsovAPI;
use PDO;
require(dirname(__FILE__).'/utils.php');
require(dirname(__FILE__).'/email-builder.php');
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

        // Silently drop completely blank submissions (observed repeated bot traffic hitting
        // this endpoint directly with an empty payload - real users always have at least a
        // name typed via the form). No DB row, no emails. Return the same success shape so
        // nothing downstream (frontend JS) sees anything different.
        if (!$name && !$email) {
            echo json_encode(['err' => '', 'msg' => 'SUCCESS']);
            return;
        }

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

        // Pre-escape
        $vSafeName  = htmlspecialchars($name,    ENT_QUOTES, 'UTF-8');
        $vSafeEmail = htmlspecialchars($email,   ENT_QUOTES, 'UTF-8');
        $vSafePhone = htmlspecialchars($phone,   ENT_QUOTES, 'UTF-8');
        $vSafeMsg   = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
        $vSafeCity  = htmlspecialchars($city,    ENT_QUOTES, 'UTF-8');
        $vSafeState = htmlspecialchars($state,   ENT_QUOTES, 'UTF-8');
        $vHeaders   = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: noreply@utsov.org\r\n";

        // Volunteer confirmation email — personalized PNG
        $mailHeaders = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: noreply@utsov.org\r\n";
        if ($email) {
            $vConfSubject = "Thank you for volunteering with UTSOV 2026!";
            $vGreeting = array("Dear {$name},", 'Thank you for signing up to volunteer.', 'Your time and energy help make Durga Puja possible for our community.');
            if ($memberrequest) $vGreeting[] = 'Membership request noted - details coming with our follow-up.';
            $imgUrl = buildEmailImage(
                'VOLUNTEER CONFIRMED', false,
                'Welcome to the UTSOV 2026 Team!',
                $vGreeting,
                null, null,
                array('Our coordinator will reach out soon with', 'details about roles, schedules, and the event.'),
                $email . '_vol'
            );
            if ($imgUrl) {
                $vConfBody =
                    "<html><body style='margin:0;padding:20px 0;background-color:#0d0000;text-align:center;font-family:Arial,sans-serif;'>"
                  . "<img src='{$imgUrl}' width='600' alt='UTSOV 2026 Volunteer Confirmed' style='display:block;margin:0 auto;max-width:100%;border:0;'>"
                  . "<p style='color:#888;font-size:11px;margin:8px auto 0;max-width:600px;'>Volunteer confirmed: {$vSafeName} | {$vSafeEmail}</p>"
                  . "</body></html>";
            } else {
                $vConfBody =
                    "<html><body style='font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto'>"
                  . "<h2 style='color:#8B1A1A'>Welcome to the UTSOV 2026 volunteer team!</h2>"
                  . "<p>Hi {$vSafeName}, thank you for signing up to volunteer!</p>"
                  . "<p style='color:#8B1A1A'>-- The UTSOV Team</p></body></html>";
            }
            mail($email, sanitizeHeader($vConfSubject), $vConfBody, $mailHeaders);
        }

        // Admin notification
        $subject = "UTSOV Volunteer Sign-Up - " . sanitizeHeader($name);
        $locationDisp = ($vSafeCity || $vSafeState) ? trim($vSafeCity . ($vSafeCity && $vSafeState ? ', ' : '') . $vSafeState) : '';
        $body = "<html><body style='font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto'>"
            . "<h2 style='color:#8B1A1A'>New Volunteer: {$vSafeName}</h2>"
            . "<table style='border-collapse:collapse;font-size:14px'>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Name:</b></td><td>{$vSafeName}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Email:</b></td><td>{$vSafeEmail}</td></tr>"
            . "<tr><td style='padding:4px 16px 4px 0'><b>Phone:</b></td><td>{$vSafePhone}</td></tr>"
            . ($locationDisp ? "<tr><td style='padding:4px 16px 4px 0'><b>Location:</b></td><td>{$locationDisp}</td></tr>" : '')
            . ($memberrequest ? "<tr><td style='padding:4px 16px 4px 0'><b>Membership:</b></td><td>Requested</td></tr>" : '')
            . ($message ? "<tr><td style='padding:4px 16px 4px 0;vertical-align:top'><b>Message:</b></td><td>{$vSafeMsg}</td></tr>" : '')
            . "</table>"
            . "</body></html>";
        notifyAdmins(sanitizeHeader($subject), $body, $mailHeaders);

        $return['msg'] = 'SUCCESS';
    } catch(PDOException $e) {
        $return['err'] = 'DB error';
        $return['msg'] = 'An error occurred. Please try again.';
    }
    echo json_encode($return);
}
?>
