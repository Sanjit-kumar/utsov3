<?php
namespace UtsovAPI;
use PDO;
require(dirname(__FILE__).'/utils.php');
require(dirname(__FILE__).'/checkin-config.php');
require(dirname(__FILE__).'/checkin-lib.php');
require(dirname(__FILE__).'/twilio-config.php');
require(dirname(__FILE__).'/sms-sender.php');
date_default_timezone_set('America/New_York');

header('Content-Type: application/json');

$_post = json_decode(file_get_contents("php://input"));
if (!$_post) { echo json_encode(['err' => 'Invalid request']); exit; }

// Every action requires the shared volunteer username/password.
$username = isset($_post->username) ? (string)$_post->username : '';
$password = isset($_post->password) ? (string)$_post->password : '';
if (!hash_equals(CHECKIN_USERNAME, $username) || !hash_equals(CHECKIN_PASSWORD, $password)) {
    echo json_encode(['err' => 'Invalid username or password']);
    exit;
}

function openCheckinDB() {
    $db = new PDO("sqlite:" . getDBPath("register"));
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA busy_timeout=5000");
    // Self-migrating, like checkout.php's openDB() — only ADDs columns, never touches rows.
    $cols = $db->query("PRAGMA table_info(tb_registrations)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('checked_in_at', $cols)) {
        $db->exec("ALTER TABLE tb_registrations ADD COLUMN checked_in_at TEXT");
    }
    if (!in_array('checked_in_by', $cols)) {
        $db->exec("ALTER TABLE tb_registrations ADD COLUMN checked_in_by TEXT");
    }
    return $db;
}

function attendeeSummary($row) {
    $foodMap = ['vegetarian' => 'Vegetarian', 'nonvegetarian' => 'Non-Vegetarian', 'nodinner' => 'No Dinner'];
    $names = json_decode($row['attendee_names'], true);
    if (!is_array($names)) $names = [$row['name']];
    $foodArr = json_decode($row['food_pref'], true);
    if (!is_array($foodArr)) $foodArr = [$row['food_pref']];

    $attendees = [];
    foreach ($names as $i => $n) {
        $n = trim((string)$n);
        $food = isset($foodArr[$i]) ? $foodArr[$i] : 'nonvegetarian';
        $attendees[] = [
            'name' => $n !== '' ? $n : ('Attendee ' . ($i + 1)),
            'food' => isset($foodMap[$food]) ? $foodMap[$food] : 'Non-Vegetarian',
        ];
    }
    return $attendees;
}

function registrationPayload($row) {
    $daysMap = ['both' => 'Both Days (Oct 17 & 18)', 'saturday' => 'Saturday, Oct 17', 'sunday' => 'Sunday, Oct 18'];
    $passTypeMap = ['full' => 'Full Package', 'concert_only' => 'Concert Only', 'mixed' => 'Mixed (Full + Concert Only)'];
    return [
        'id'            => (int)$row['id'],
        'name'          => $row['name'],
        'email'         => $row['email'],
        'phone'         => $row['phone'],
        'qty'           => (int)$row['qty'],
        'adults'        => (int)(isset($row['adults']) ? $row['adults'] : 0),
        'kids6to12'     => (int)(isset($row['kids_6to12']) ? $row['kids_6to12'] : 0),
        'children'      => (int)(isset($row['children']) ? $row['children'] : 0),
        'days'          => isset($daysMap[$row['days_selection']]) ? $daysMap[$row['days_selection']] : 'Both Days',
        'passType'      => isset($passTypeMap[$row['pass_type']]) ? $passTypeMap[$row['pass_type']] : 'Full Package',
        'total'         => number_format((float)$row['total_amount'], 2),
        'status'        => $row['status'],
        'isPaid'        => ($row['status'] === 'paid'),
        'attendees'     => attendeeSummary($row),
        'checkedIn'     => !empty($row['checked_in_at']),
        'checkedInAt'   => $row['checked_in_at'],
        'checkedInBy'   => $row['checked_in_by'],
    ];
}

// Resolves which registration id a request refers to: a scanned+signed QR token, OR a
// manually-typed registration id (fallback for when a camera/QR can't be read — the
// signature check is skipped for manual entry since the request already passed the
// volunteer username/password gate above; a raw id alone isn't reachable by an outsider).
function resolveCheckinId($post) {
    if (!empty($post->token)) {
        return verifyCheckinToken((string)$post->token);
    }
    if (!empty($post->manualId)) {
        $id = (int)$post->manualId;
        return $id > 0 ? $id : null;
    }
    return null;
}

$action = isset($_post->action) ? (string)$_post->action : '';
$db = openCheckinDB();

switch ($action) {

    // Scan a QR (or manually-entered order id) and return full details WITHOUT marking
    // check-in yet — lets the volunteer review payment/pass details before confirming.
    case 'lookup': {
        $id = resolveCheckinId($_post);
        if ($id === null) { echo json_encode(['err' => 'Invalid or forged QR code']); exit; }

        $sel = $db->prepare("SELECT * FROM tb_registrations WHERE id = :id");
        $sel->execute([':id' => $id]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['err' => 'Registration not found']); exit; }

        echo json_encode(['ok' => true, 'registration' => registrationPayload($row)]);
        break;
    }

    // Finalize check-in: marks checked_in_at, sends the check-in SMS if opted in.
    // Idempotent — confirming an already-checked-in registration just returns its
    // existing check-in info rather than overwriting the original timestamp.
    case 'confirm': {
        $station = mb_substr(isset($_post->station) ? (string)$_post->station : 'Front Gate', 0, 100);
        $id = resolveCheckinId($_post);
        if ($id === null) { echo json_encode(['err' => 'Invalid or forged QR code']); exit; }

        $sel = $db->prepare("SELECT * FROM tb_registrations WHERE id = :id");
        $sel->execute([':id' => $id]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['err' => 'Registration not found']); exit; }

        if (!empty($row['checked_in_at'])) {
            // Already checked in — do NOT re-send SMS or overwrite the timestamp.
            echo json_encode(['ok' => true, 'alreadyCheckedIn' => true, 'registration' => registrationPayload($row)]);
            break;
        }

        if ($row['status'] !== 'paid') {
            echo json_encode(['err' => 'Not marked as paid — do not check in', 'registration' => registrationPayload($row)]);
            break;
        }

        $now = date('Y-m-d H:i:s');
        $upd = $db->prepare("UPDATE tb_registrations SET checked_in_at = :now, checked_in_by = :station WHERE id = :id");
        $upd->execute([':now' => $now, ':station' => $station, ':id' => $id]);

        $row['checked_in_at'] = $now;
        $row['checked_in_by'] = $station;

        if (!empty($row['sms_updates'])) {
            $qty = (int)$row['qty'];
            $smsBody = "UTSOV 2026: You're checked in! Welcome, {$row['name']} (party of {$qty}). Enjoy the celebration! Reply STOP to opt out.";
            sendTwilioSMS($row['phone'], $smsBody);
        }

        echo json_encode(['ok' => true, 'alreadyCheckedIn' => false, 'registration' => registrationPayload($row)]);
        break;
    }

    // Live running list + count for the check-in page's sidebar. Polled periodically.
    case 'list': {
        $countStmt = $db->query("SELECT COUNT(*) FROM tb_registrations WHERE status='paid' AND checked_in_at IS NOT NULL");
        $checkedInCount = (int)$countStmt->fetchColumn();

        $totalStmt = $db->query("SELECT COUNT(*) FROM tb_registrations WHERE status='paid'");
        $totalPaid = (int)$totalStmt->fetchColumn();

        $headcountStmt = $db->query("SELECT COALESCE(SUM(qty),0) FROM tb_registrations WHERE status='paid' AND checked_in_at IS NOT NULL");
        $headcount = (int)$headcountStmt->fetchColumn();

        $recentStmt = $db->query(
            "SELECT id, name, qty, checked_in_at, checked_in_by FROM tb_registrations
             WHERE status='paid' AND checked_in_at IS NOT NULL
             ORDER BY checked_in_at DESC LIMIT 25"
        );
        $recent = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok' => true,
            'checkedInRegistrations' => $checkedInCount,
            'totalPaidRegistrations' => $totalPaid,
            'checkedInHeadcount'     => $headcount,
            'recent'                 => $recent,
        ]);
        break;
    }

    default:
        echo json_encode(['err' => 'Unknown action']);
}
