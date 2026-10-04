<?php
namespace UtsovAPI;
use PDO;
require(dirname(__FILE__).'/utils.php');
require(dirname(__FILE__).'/checkin-config.php'); // CHECKIN_SIGNING_SECRET, used by checkin-lib.php for QR verification
require(dirname(__FILE__).'/checkin-lib.php');
require(dirname(__FILE__).'/twilio-config.php');
require(dirname(__FILE__).'/sms-sender.php');
date_default_timezone_set('America/New_York');

header('Content-Type: application/json');

$_post = json_decode(file_get_contents("php://input"));
if (!$_post) { echo json_encode(['err' => 'Invalid request']); exit; }

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
    // Per-attendee check-in: JSON array of booleans, one per pass on the registration (index
    // matches attendee_names/food_pref). Lets a party check in across multiple arrivals instead
    // of all-or-nothing. checked_in_at/checked_in_by above now mean "last check-in action here",
    // not "fully checked in" — use the computed checkInStatus field for that.
    if (!in_array('checked_in_attendees', $cols)) {
        $db->exec("ALTER TABLE tb_registrations ADD COLUMN checked_in_attendees TEXT");
    }
    return $db;
}

// Parses checked_in_attendees into a boolean array of exactly $qty entries (missing/short/
// corrupt data defaults every slot to not-checked-in, never to checked-in).
function parsePresentArray($row) {
    $qty = (int)$row['qty'];
    $arr = json_decode(isset($row['checked_in_attendees']) ? $row['checked_in_attendees'] : '', true);
    if (!is_array($arr)) $arr = [];
    $out = [];
    for ($i = 0; $i < $qty; $i++) {
        $out[] = !empty($arr[$i]);
    }
    return $out;
}

function attendeeSummary($row) {
    $foodMap = ['vegetarian' => 'Vegetarian', 'nonvegetarian' => 'Non-Vegetarian', 'nodinner' => 'No Dinner'];
    $names = json_decode($row['attendee_names'], true);
    if (!is_array($names)) $names = [$row['name']];
    $foodArr = json_decode($row['food_pref'], true);
    if (!is_array($foodArr)) $foodArr = [$row['food_pref']];
    $present = parsePresentArray($row);

    $attendees = [];
    foreach ($names as $i => $n) {
        $n = trim((string)$n);
        $food = isset($foodArr[$i]) ? $foodArr[$i] : 'nonvegetarian';
        $attendees[] = [
            'name'      => $n !== '' ? $n : ('Attendee ' . ($i + 1)),
            'food'      => isset($foodMap[$food]) ? $foodMap[$food] : 'Non-Vegetarian',
            'checkedIn' => isset($present[$i]) ? $present[$i] : false,
        ];
    }
    return $attendees;
}

function registrationPayload($row) {
    $daysMap = ['both' => 'Both Days (Oct 17 & 18)', 'saturday' => 'Saturday, Oct 17', 'sunday' => 'Sunday, Oct 18'];
    $passTypeMap = ['full' => 'Full Package', 'concert_only' => 'Concert Only', 'mixed' => 'Mixed (Full + Concert Only)'];
    $present = parsePresentArray($row);
    $qty = (int)$row['qty'];
    $checkedInCount = count(array_filter($present));
    $status = $checkedInCount === 0 ? 'none' : ($checkedInCount >= $qty ? 'full' : 'partial');
    return [
        'id'              => (int)$row['id'],
        'name'            => $row['name'],
        'email'           => $row['email'],
        'phone'           => $row['phone'],
        'qty'             => $qty,
        'adults'          => (int)(isset($row['adults']) ? $row['adults'] : 0),
        'kids6to12'       => (int)(isset($row['kids_6to12']) ? $row['kids_6to12'] : 0),
        'children'        => (int)(isset($row['children']) ? $row['children'] : 0),
        'days'            => isset($daysMap[$row['days_selection']]) ? $daysMap[$row['days_selection']] : 'Both Days',
        'passType'        => isset($passTypeMap[$row['pass_type']]) ? $passTypeMap[$row['pass_type']] : 'Full Package',
        'total'           => number_format((float)$row['total_amount'], 2),
        'status'          => $row['status'],
        'isPaid'          => ($row['status'] === 'paid'),
        'attendees'       => attendeeSummary($row),
        'checkedInCount'  => $checkedInCount,
        'checkInStatus'   => $status,          // 'none' | 'partial' | 'full'
        'checkedIn'       => ($status === 'full'), // kept for older clients: true only when everyone's in
        'checkedInAt'     => $row['checked_in_at'],
        'checkedInBy'     => $row['checked_in_by'],
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

    // Save per-attendee check-in state. `present` is a boolean array (one entry per pass on
    // the registration, same order as attendee_names) reflecting who the volunteer has
    // ticked as physically here *as of this submit* — it fully replaces the stored state, so
    // un-ticking someone is a valid correction, not just additive. Supports partial arrivals:
    // calling this again later with more boxes checked just adds those people.
    case 'confirm': {
        $station = mb_substr(isset($_post->station) ? (string)$_post->station : 'Front Gate', 0, 100);
        $id = resolveCheckinId($_post);
        if ($id === null) { echo json_encode(['err' => 'Invalid or forged QR code']); exit; }

        $sel = $db->prepare("SELECT * FROM tb_registrations WHERE id = :id");
        $sel->execute([':id' => $id]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['err' => 'Registration not found']); exit; }

        if ($row['status'] !== 'paid') {
            echo json_encode(['err' => 'Not marked as paid — do not check in', 'registration' => registrationPayload($row)]);
            break;
        }

        $qty = (int)$row['qty'];
        $before = parsePresentArray($row);
        $wasAnyIn = in_array(true, $before, true);

        // Build the new present[] from the submitted array; default to "unchanged" if the
        // client didn't send one (e.g. an old caller still doing all-or-nothing confirm).
        if (isset($_post->present) && is_array($_post->present)) {
            $present = [];
            for ($i = 0; $i < $qty; $i++) {
                $present[] = !empty($_post->present[$i]);
            }
        } else {
            // Back-compat: no per-attendee list supplied — treat as "mark everyone present".
            $present = array_fill(0, $qty, true);
        }

        $now = date('Y-m-d H:i:s');
        $upd = $db->prepare("UPDATE tb_registrations SET checked_in_attendees = :present, checked_in_at = :now, checked_in_by = :station WHERE id = :id");
        $upd->execute([':present' => json_encode($present), ':now' => $now, ':station' => $station, ':id' => $id]);

        $row['checked_in_attendees'] = json_encode($present);
        $row['checked_in_at'] = $now;
        $row['checked_in_by'] = $station;

        // Send the "you're checked in" SMS only once — on the transition from nobody-in to
        // somebody-in — never again on later partial updates for the same registration.
        $isAnyInNow = in_array(true, $present, true);
        if (!$wasAnyIn && $isAnyInNow && !empty($row['sms_updates'])) {
            $smsBody = "UTSOV 2026: You're checked in! Welcome, {$row['name']} (party of {$qty}). Enjoy the celebration! Reply STOP to opt out.";
            sendTwilioSMS($row['phone'], $smsBody);
        }

        echo json_encode(['ok' => true, 'registration' => registrationPayload($row)]);
        break;
    }

    // Live stats for the header. Polled periodically.
    case 'list': {
        $stmt = $db->query("SELECT qty, checked_in_attendees FROM tb_registrations WHERE status='paid'");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalPaid = count($rows);
        $fullyIn = 0; $partiallyIn = 0; $headcountIn = 0; $totalHeadcount = 0;
        foreach ($rows as $r) {
            $present = parsePresentArray($r);
            $qty = (int)$r['qty'];
            $inCount = count(array_filter($present));
            $totalHeadcount += $qty;
            $headcountIn += $inCount;
            if ($inCount >= $qty && $qty > 0) $fullyIn++;
            elseif ($inCount > 0) $partiallyIn++;
        }

        $recentStmt = $db->query(
            "SELECT id, name, qty, checked_in_attendees, checked_in_at, checked_in_by FROM tb_registrations
             WHERE status='paid' AND checked_in_at IS NOT NULL
             ORDER BY checked_in_at DESC LIMIT 25"
        );
        $recent = array_map(function($r) {
            $present = parsePresentArray($r);
            return [
                'id' => $r['id'], 'name' => $r['name'], 'qty' => $r['qty'],
                'checkedInCount' => count(array_filter($present)),
                'checked_in_at' => $r['checked_in_at'], 'checked_in_by' => $r['checked_in_by'],
            ];
        }, $recentStmt->fetchAll(PDO::FETCH_ASSOC));

        echo json_encode([
            'ok' => true,
            'totalPaidRegistrations' => $totalPaid,
            'fullyCheckedIn'         => $fullyIn,
            'partiallyCheckedIn'     => $partiallyIn,
            'checkedInHeadcount'     => $headcountIn,
            'totalHeadcount'         => $totalHeadcount,
            'recent'                 => $recent,
        ]);
        break;
    }

    // Full roster for the browsable/searchable list — every paid registration, lightweight
    // fields only (full details are fetched fresh via 'lookup' when a row is opened, so this
    // list can't go stale and cause a wrong check-in decision).
    case 'roster': {
        $stmt = $db->query(
            "SELECT id, name, qty, days_selection, checked_in_attendees FROM tb_registrations
             WHERE status='paid' ORDER BY name COLLATE NOCASE ASC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $daysMap = ['both' => 'Both Days', 'saturday' => 'Saturday', 'sunday' => 'Sunday'];
        $roster = array_map(function($r) use ($daysMap) {
            $present = parsePresentArray($r);
            $qty = (int)$r['qty'];
            $inCount = count(array_filter($present));
            $status = $inCount === 0 ? 'none' : ($inCount >= $qty ? 'full' : 'partial');
            return [
                'id'             => (int)$r['id'],
                'name'           => $r['name'],
                'qty'            => $qty,
                'days'           => isset($daysMap[$r['days_selection']]) ? $daysMap[$r['days_selection']] : 'Both Days',
                'checkedInCount' => $inCount,
                'checkInStatus'  => $status,
            ];
        }, $rows);
        echo json_encode(['ok' => true, 'roster' => $roster]);
        break;
    }

    default:
        echo json_encode(['err' => 'Unknown action']);
}
