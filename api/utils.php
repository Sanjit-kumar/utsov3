<?php
namespace UtsovAPI;

// Backward-compatible with legacy api scripts; adds "capture" and "contact" cases
class UtsovDB extends \SQLite3 {
    function __construct($dbname) {
        $this->open(self::buildPath($dbname));
    }
    private static function buildPath($dbname) {
        $dir = dirname(__FILE__) . '/db';
        switch($dbname) {
            case "utsov":    return "$dir/utsov.db";
            default:         return "$dir/capture.db";
        }
    }
}

class UtsovException extends \Exception { }

function getDBPath($dbname) {
    $dir = dirname(__FILE__) . '/db';
    switch($dbname) {
        case "utsov":                                    return "$dir/utsov.db";
        case "patron":  case "register":                 return "$dir/registration.db";
        case "volunteer": case "contest": case "sponsor":
        case "donation": case "capture":  case "contact": return "$dir/capture.db";
        default:                                          return "$dir/utsov.db";
    }
}

function is_ajax() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function get_client_ip() {
    $keys = ['HTTP_CLIENT_IP','HTTP_X_FORWARDED_FOR','HTTP_X_FORWARDED',
             'HTTP_FORWARDED_FOR','HTTP_FORWARDED','REMOTE_ADDR'];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return 'UNKNOWN';
}

function logMessage($message) {
    // error_log("[".date("H:i:s l jS F Y")."]: ".$message."\n", 3, "/var/tmp/utsovapi.log");
}

/**
 * Sends an admin notification email to every admin currently in tb_admin_users
 * (Manage Admins tab in admin.php) - stays in sync automatically as admins are
 * added/removed there, instead of a hardcoded address duplicated across files.
 * Falls back to utsov@utsov.org if the admin DB can't be read, so a DB hiccup
 * never silently drops a notification.
 */
function notifyAdmins($subject, $body, $mailHeaders) {
    // utsov@utsov.org is the shared/monitored inbox - always included, not just a fallback.
    $recipients = ['utsov@utsov.org'];
    try {
        $adminDbPath = dirname(__FILE__) . '/db/admin.db';
        if (file_exists($adminDbPath)) {
            $db = new \PDO('sqlite:' . $adminDbPath);
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $rows = $db->query("SELECT email FROM tb_admin_users")->fetchAll(\PDO::FETCH_COLUMN, 0);
            foreach ($rows as $email) {
                $email = trim((string)$email);
                if ($email !== '') $recipients[] = $email;
            }
        }
    } catch (\Exception $e) {
        // admin DB unreadable - utsov@utsov.org above still gets notified regardless
    }

    foreach (array_unique(array_map('strtolower', $recipients)) as $to) {
        mail($to, $subject, $body, $mailHeaders);
    }
}
?>
