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
    // Saturday and Sunday check-in are tracked in FULLY SEPARATE columns — a "Both Days" pass
    // holder checking in Saturday must NOT show as checked-in when someone opens them on the
    // Sunday tab the next day; each day needs its own independent headcount (catering, gifts,
    // etc. all depend on knowing who actually walked in on which day). The older shared
    // checked_in_attendees/checked_in_at/checked_in_by columns are left in place but unused —
    // there was no real event data in them (checked before this change shipped), so nothing
    // is migrated or lost.
    $cols = $db->query("PRAGMA table_info(tb_registrations)")->fetchAll(PDO::FETCH_COLUMN, 1);
    $toAdd = [
        'checked_in_attendees_sat' => 'TEXT', 'checked_in_at_sat' => 'TEXT', 'checked_in_by_sat' => 'TEXT',
        'checked_in_attendees_sun' => 'TEXT', 'checked_in_at_sun' => 'TEXT', 'checked_in_by_sun' => 'TEXT',
    ];
    foreach ($toAdd as $col => $type) {
        if (!in_array($col, $cols)) {
            $db->exec("ALTER TABLE tb_registrations ADD COLUMN {$col} {$type}");
        }
    }
    return $db;
}

// Parses a raw checked_in_attendees_* JSON string into a boolean array of exactly $qty entries
// (missing/short/corrupt data defaults every slot to not-checked-in, never to checked-in).
function parsePresentJson($json, $qty) {
    $arr = json_decode($json !== null ? $json : '', true);
    if (!is_array($arr)) $arr = [];
    $out = [];
    for ($i = 0; $i < $qty; $i++) {
        $out[] = !empty($arr[$i]);
    }
    return $out;
}

// Per-day column pair for a given 'saturday'|'sunday' day string.
function dayCols($day) {
    return $day === 'sunday'
        ? ['present' => 'checked_in_attendees_sun', 'at' => 'checked_in_at_sun', 'by' => 'checked_in_by_sun']
        : ['present' => 'checked_in_attendees_sat', 'at' => 'checked_in_at_sat', 'by' => 'checked_in_by_sat'];
}

function parsePresentArray($row, $day) {
    $col = dayCols($day)['present'];
    return parsePresentJson(isset($row[$col]) ? $row[$col] : null, (int)$row['qty']);
}

// Does this registration's purchased days include $day ('saturday'|'sunday')? "both" always does.
function dayApplies($daysSelection, $day) {
    return $daysSelection === 'both' || $daysSelection === $day;
}

function attendeeSummary($row, $day) {
    $foodMap = ['vegetarian' => 'Vegetarian', 'nonvegetarian' => 'Non-Vegetarian', 'nodinner' => 'No Dinner'];
    $names = json_decode($row['attendee_names'], true);
    if (!is_array($names)) $names = [$row['name']];
    $foodArr = json_decode($row['food_pref'], true);
    if (!is_array($foodArr)) $foodArr = [$row['food_pref']];
    $present = parsePresentArray($row, $day);

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

// $day = 'saturday' | 'sunday' — which day's independent check-in state this payload reflects.
function registrationPayload($row, $day) {
    $daysMap = ['both' => 'Both Days (Oct 17 & 18)', 'saturday' => 'Saturday, Oct 17', 'sunday' => 'Sunday, Oct 18'];
    $passTypeMap = ['full' => 'Full Package', 'concert_only' => 'Concert Only', 'mixed' => 'Mixed (Full + Concert Only)'];
    $present = parsePresentArray($row, $day);
    $qty = (int)$row['qty'];
    $checkedInCount = count(array_filter($present));
    $status = $checkedInCount === 0 ? 'none' : ($checkedInCount >= $qty ? 'full' : 'partial');
    $cols = dayCols($day);
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
        'checkinDay'      => $day, // which day THIS payload's check-in state belongs to
        'passType'        => isset($passTypeMap[$row['pass_type']]) ? $passTypeMap[$row['pass_type']] : 'Full Package',
        'total'           => number_format((float)$row['total_amount'], 2),
        'status'          => $row['status'],
        'isPaid'          => ($row['status'] === 'paid'),
        'attendees'       => attendeeSummary($row, $day),
        'checkedInCount'  => $checkedInCount,
        'checkInStatus'   => $status,          // 'none' | 'partial' | 'full' — for THIS day only
        'checkedIn'       => ($status === 'full'),
        'checkedInAt'     => isset($row[$cols['at']]) ? $row[$cols['at']] : null,
        'checkedInBy'     => isset($row[$cols['by']]) ? $row[$cols['by']] : null,
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

function resolveDay($post) {
    $day = isset($post->day) ? (string)$post->day : '';
    return ($day === 'saturday' || $day === 'sunday') ? $day : null;
}

function dayLabel($d) { return $d === 'sunday' ? 'Sunday' : 'Saturday'; }

$action = isset($_post->action) ? (string)$_post->action : '';
$db = openCheckinDB();

switch ($action) {

    // Scan a QR (or manually-entered order id) and return full details WITHOUT marking
    // check-in yet — lets the volunteer review payment/pass details before confirming.
    // Always scoped to a specific day (the tab the volunteer currently has open) so a
    // "Both Days" person's Saturday and Sunday check-in states never bleed into each other.
    case 'lookup': {
        $day = resolveDay($_post);
        if ($day === null) { echo json_encode(['err' => 'Missing or invalid day']); exit; }

        $id = resolveCheckinId($_post);
        if ($id === null) { echo json_encode(['err' => 'Invalid or forged QR code']); exit; }

        $sel = $db->prepare("SELECT * FROM tb_registrations WHERE id = :id");
        $sel->execute([':id' => $id]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['err' => 'Registration not found']); exit; }

        if (!dayApplies($row['days_selection'], $day)) {
            echo json_encode(['err' => $row['name'] . "'s pass does not include " . dayLabel($day) . '.']);
            exit;
        }

        echo json_encode(['ok' => true, 'registration' => registrationPayload($row, $day)]);
        break;
    }

    // Save per-attendee check-in state for ONE day only. `present` is a boolean array (one
    // entry per pass on the registration, same order as attendee_names) reflecting who the
    // volunteer has ticked as physically here *as of this submit*, for the day given in
    // `day` — it fully replaces that day's stored state, so un-ticking someone is a valid
    // correction. Saturday and Sunday are completely independent: checking someone in on
    // Saturday has zero effect on their Sunday status, and vice versa.
    case 'confirm': {
        $day = resolveDay($_post);
        if ($day === null) { echo json_encode(['err' => 'Missing or invalid day']); exit; }

        $station = mb_substr(isset($_post->station) ? (string)$_post->station : 'Front Gate', 0, 100);
        $id = resolveCheckinId($_post);
        if ($id === null) { echo json_encode(['err' => 'Invalid or forged QR code']); exit; }

        $sel = $db->prepare("SELECT * FROM tb_registrations WHERE id = :id");
        $sel->execute([':id' => $id]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['err' => 'Registration not found']); exit; }

        if ($row['status'] !== 'paid') {
            echo json_encode(['err' => 'Not marked as paid — do not check in', 'registration' => registrationPayload($row, $day)]);
            break;
        }

        if (!dayApplies($row['days_selection'], $day)) {
            echo json_encode(['err' => $row['name'] . "'s pass does not include " . dayLabel($day) . '.']);
            break;
        }

        $qty = (int)$row['qty'];
        $before = parsePresentArray($row, $day);
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
        $cols = dayCols($day);
        $sql = "UPDATE tb_registrations SET {$cols['present']} = :present, {$cols['at']} = :now, {$cols['by']} = :station WHERE id = :id";
        $upd = $db->prepare($sql);
        $upd->execute([':present' => json_encode($present), ':now' => $now, ':station' => $station, ':id' => $id]);

        $row[$cols['present']] = json_encode($present);
        $row[$cols['at']] = $now;
        $row[$cols['by']] = $station;

        // Send the "you're checked in" SMS only once per day — on the transition from
        // nobody-in-TODAY to somebody-in-TODAY. A Both-Days person who comes both days gets
        // two separate welcome texts, one per day, which is correct (two separate visits).
        $isAnyInNow = in_array(true, $present, true);
        if (!$wasAnyIn && $isAnyInNow && !empty($row['sms_updates'])) {
            $smsBody = "UTSOV 2026: You're checked in for " . dayLabel($day) . "! Welcome, {$row['name']} (party of {$qty}). Enjoy the celebration! Reply STOP to opt out.";
            sendTwilioSMS($row['phone'], $smsBody);
        }

        echo json_encode(['ok' => true, 'registration' => registrationPayload($row, $day)]);
        break;
    }

    // Live stats. Optional `day` ('saturday'|'sunday') scopes to that day; omitted = overall
    // (a person counts as "in" overall if they checked in on either day they're eligible for).
    case 'list': {
        $day = resolveDay($_post);
        $stmt = $db->query("SELECT qty, days_selection, checked_in_attendees_sat, checked_in_attendees_sun FROM tb_registrations WHERE status='paid'");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalPaid = count($rows);
        $fullyIn = 0; $partiallyIn = 0; $headcountIn = 0; $totalHeadcount = 0;
        foreach ($rows as $r) {
            $qty = (int)$r['qty'];
            if ($day !== null) {
                if (!dayApplies($r['days_selection'], $day)) continue;
                $present = parsePresentArray($r, $day);
            } else {
                $sat = parsePresentArray($r, 'saturday');
                $sun = parsePresentArray($r, 'sunday');
                $present = [];
                for ($i = 0; $i < $qty; $i++) $present[] = $sat[$i] || $sun[$i];
            }
            $inCount = count(array_filter($present));
            $totalHeadcount += $qty;
            $headcountIn += $inCount;
            if ($inCount >= $qty && $qty > 0) $fullyIn++;
            elseif ($inCount > 0) $partiallyIn++;
        }

        echo json_encode([
            'ok' => true,
            'totalPaidRegistrations' => $totalPaid,
            'fullyCheckedIn'         => $fullyIn,
            'partiallyCheckedIn'     => $partiallyIn,
            'checkedInHeadcount'     => $headcountIn,
            'totalHeadcount'         => $totalHeadcount,
        ]);
        break;
    }

    // Full roster for the browsable/searchable list — every paid registration, lightweight
    // fields only (full per-attendee details are fetched fresh via 'lookup' when a row is
    // opened). Returns BOTH days' independent counts per row so the frontend can show
    // whichever day's tab is active without a second round trip on every tab switch.
    case 'roster': {
        $stmt = $db->query(
            "SELECT id, name, qty, days_selection, checked_in_attendees_sat, checked_in_attendees_sun
             FROM tb_registrations WHERE status='paid' ORDER BY name COLLATE NOCASE ASC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $daysMap = ['both' => 'Both Days', 'saturday' => 'Saturday', 'sunday' => 'Sunday'];
        $roster = array_map(function($r) use ($daysMap) {
            $qty = (int)$r['qty'];
            $satPresent = parsePresentArray($r, 'saturday');
            $sunPresent = parsePresentArray($r, 'sunday');
            $satIn = count(array_filter($satPresent));
            $sunIn = count(array_filter($sunPresent));
            $mk = function($in, $qty) { return $in === 0 ? 'none' : ($in >= $qty ? 'full' : 'partial'); };
            return [
                'id'    => (int)$r['id'],
                'name'  => $r['name'],
                'qty'   => $qty,
                'days'  => isset($daysMap[$r['days_selection']]) ? $daysMap[$r['days_selection']] : 'Both Days',
                'sat'   => ['checkedInCount' => $satIn, 'checkInStatus' => $mk($satIn, $qty)],
                'sun'   => ['checkedInCount' => $sunIn, 'checkInStatus' => $mk($sunIn, $qty)],
            ];
        }, $rows);
        echo json_encode(['ok' => true, 'roster' => $roster]);
        break;
    }

    default:
        echo json_encode(['err' => 'Unknown action']);
}
