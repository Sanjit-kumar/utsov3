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
        // Anniversary gift — deliberately the OPPOSITE of check-in: ONE shared flag per
        // registration, not per day and not per attendee ("1 gift to 1 registration"). A
        // Both-Days person who collects it Saturday must show as already-given on Sunday too,
        // so a second volunteer at a different table/day doesn't hand out a duplicate.
        'gift_given' => 'INTEGER', 'gift_given_at' => 'TEXT', 'gift_given_by' => 'TEXT', 'gift_given_day' => 'TEXT',
        // Who referred/coordinated this registration (e.g. "Somen Poddar") -- a second
        // disambiguator alongside phone for when two paid registrations share a name;
        // optional, populated manually from payment notes where known, blank otherwise.
        'referred_by' => 'TEXT',
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

// Zelle/Sulekha are explicitly tagged via payment_method; PayPal/website checkouts leave
// payment_method blank and are identified instead by having a paypal_order_id — every paid
// row has exactly one of these two signals (verified against live data before building this).
function paymentSource($row) {
    $pm = isset($row['payment_method']) ? $row['payment_method'] : null;
    if ($pm === 'zelle') return 'Zelle';
    if ($pm === 'sulekha') return 'Sulekha';
    if ($pm === 'donor') return 'Patron Donor';
    if ($pm === 'check') return 'Check';
    if ($pm === 'cash') return 'Cash';
    if ($pm === 'card') return 'Card';
    return 'PayPal';
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
        'phoneLast4'      => phoneLast4($row['phone']),
        'referredBy'      => isset($row['referred_by']) ? $row['referred_by'] : '',
        'qty'             => $qty,
        'adults'          => (int)(isset($row['adults']) ? $row['adults'] : 0),
        'kids6to12'       => (int)(isset($row['kids_6to12']) ? $row['kids_6to12'] : 0),
        'children'        => (int)(isset($row['children']) ? $row['children'] : 0),
        'days'            => isset($daysMap[$row['days_selection']]) ? $daysMap[$row['days_selection']] : 'Both Days',
        'checkinDay'      => $day, // which day THIS payload's check-in state belongs to
        'passType'        => isset($passTypeMap[$row['pass_type']]) ? $passTypeMap[$row['pass_type']] : 'Full Package',
        'paymentSource'   => paymentSource($row),
        'total'           => number_format((float)$row['total_amount'], 2),
        'status'          => $row['status'],
        'isPaid'          => ($row['status'] === 'paid'),
        'attendees'       => attendeeSummary($row, $day),
        'checkedInCount'  => $checkedInCount,
        'checkInStatus'   => $status,          // 'none' | 'partial' | 'full' — for THIS day only
        'checkedIn'       => ($status === 'full'),
        'checkedInAt'     => isset($row[$cols['at']]) ? $row[$cols['at']] : null,
        'checkedInBy'     => isset($row[$cols['by']]) ? $row[$cols['by']] : null,
        // Shared across both days on purpose — see the column comment in openCheckinDB().
        'giftGiven'       => !empty($row['gift_given']),
        'giftGivenAt'     => isset($row['gift_given_at']) ? $row['gift_given_at'] : null,
        'giftGivenBy'     => isset($row['gift_given_by']) ? $row['gift_given_by'] : null,
        'giftGivenDay'    => isset($row['gift_given_day']) ? $row['gift_given_day'] : null,
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

// Last 4 digits of phone only -- enough to disambiguate two same-name registrations at the
// door without putting a full phone number up on a shared check-in screen.
function phoneLast4($phone) {
    $digits = preg_replace('/\D+/', '', (string)$phone);
    return strlen($digits) >= 4 ? substr($digits, -4) : '';
}

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

    // Toggle the anniversary-gift flag. Unlike check-in, this is ONE shared flag per
    // registration regardless of day — `given: true` marks it given (blocking a second
    // hand-out on the other day), `given: false` undoes an accidental mark. `day` here is
    // only for the record (which day's table actually gave it out), not for scoping state.
    case 'giftConfirm': {
        $day = resolveDay($_post); // optional, for the record only
        $station = mb_substr(isset($_post->station) ? (string)$_post->station : 'Front Gate', 0, 100);
        $given = !empty($_post->given);
        $id = resolveCheckinId($_post);
        if ($id === null) { echo json_encode(['err' => 'Invalid or forged QR code']); exit; }

        $sel = $db->prepare("SELECT * FROM tb_registrations WHERE id = :id");
        $sel->execute([':id' => $id]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['err' => 'Registration not found']); exit; }

        if ($given) {
            $now = date('Y-m-d H:i:s');
            $upd = $db->prepare("UPDATE tb_registrations SET gift_given=1, gift_given_at=:now, gift_given_by=:station, gift_given_day=:day WHERE id=:id");
            $upd->execute([':now' => $now, ':station' => $station, ':day' => $day, ':id' => $id]);
            $row['gift_given'] = 1; $row['gift_given_at'] = $now; $row['gift_given_by'] = $station; $row['gift_given_day'] = $day;
        } else {
            $upd = $db->prepare("UPDATE tb_registrations SET gift_given=0, gift_given_at=NULL, gift_given_by=NULL, gift_given_day=NULL WHERE id=:id");
            $upd->execute([':id' => $id]);
            $row['gift_given'] = 0; $row['gift_given_at'] = null; $row['gift_given_by'] = null; $row['gift_given_day'] = null;
        }

        echo json_encode(['ok' => true, 'registration' => registrationPayload($row, $day ?: 'saturday')]);
        break;
    }

    // Walk-in, pay-at-the-gate registration -- lets a volunteer add someone who shows up
    // unregistered and immediately mark them paid/checked-in-eligible, without a separate
    // sync step. Pricing is computed server-side from the SAME table cart.html uses (never
    // trust a client-submitted total here -- this is real money changing hands at the gate).
    case 'quickAdd': {
        $station = mb_substr(isset($_post->station) ? (string)$_post->station : 'Front Gate', 0, 100);
        $name  = mb_substr(isset($_post->name)  ? (string)$_post->name  : '', 0, 200);
        $email = mb_substr(isset($_post->email) ? (string)$_post->email : '', 0, 254);
        $phone = mb_substr(isset($_post->phone) ? (string)$_post->phone : '', 0, 50);

        if ($name === '') { echo json_encode(['err' => 'Name is required.']); break; }

        $allowedDays = ['both', 'saturday', 'sunday'];
        $day = (isset($_post->day) && in_array($_post->day, $allowedDays, true)) ? $_post->day : 'both';

        // Mirrors cart.html's PRICE_TABLE (Patron/Student/Kids x Full Package/Concert Only).
        $PRICE = [
            'patronFull'     => ['both' => 150, 'saturday' => 95, 'sunday' => 85],
            'studentFull'    => ['both' => 115, 'saturday' => 75, 'sunday' => 65],
            'kidsFull'       => ['both' => 85,  'saturday' => 50, 'sunday' => 50],
            'patronConcert'  => ['both' => 130, 'saturday' => 80, 'sunday' => 75],
            'studentConcert' => ['both' => 105, 'saturday' => 70, 'sunday' => 60],
            'kidsConcert'    => ['both' => 75,  'saturday' => 45, 'sunday' => 45],
        ];

        $counts = [];
        foreach (array_keys($PRICE) as $k) {
            $counts[$k] = max(0, min(50, (int)(isset($_post->$k) ? $_post->$k : 0)));
        }
        $childrenFree = max(0, min(50, (int)(isset($_post->childrenFree) ? $_post->childrenFree : 0)));

        $qty = array_sum($counts) + $childrenFree;
        if ($qty <= 0) { echo json_encode(['err' => 'Add at least one pass.']); break; }

        $total = 0.0;
        foreach ($counts as $k => $c) {
            if ($c > 0) $total += $c * $PRICE[$k][$day];
        }
        $total = round($total, 2);

        // Manual override for walk-ins that don't fit the standard pass pricing (e.g. a flat
        // Pujo-only/donation rate) -- the adult/kid counts above still drive headcount and
        // attendee records as normal, only the dollar total is replaced.
        if (isset($_post->customTotal) && is_numeric($_post->customTotal) && (float)$_post->customTotal > 0) {
            $total = round((float)$_post->customTotal, 2);
        }

        $adults    = $counts['patronFull'] + $counts['studentFull'] + $counts['patronConcert'] + $counts['studentConcert'];
        $kids6to12 = $counts['kidsFull'] + $counts['kidsConcert'];
        $children  = $childrenFree;

        $fullCount    = $counts['patronFull'] + $counts['studentFull'] + $counts['kidsFull'];
        $concertCount = $counts['patronConcert'] + $counts['studentConcert'] + $counts['kidsConcert'];
        $passType = ($fullCount > 0 && $concertCount > 0) ? 'mixed' : ($concertCount > 0 ? 'concert_only' : 'full');
        $isStudent = ($counts['studentFull'] + $counts['studentConcert']) > 0 ? 1 : 0;

        $foodChoice = (isset($_post->foodPref) && $_post->foodPref === 'vegetarian') ? 'vegetarian' : 'nonvegetarian';
        $foodArr = [];
        // Concert-Only passes include no meal regardless of the veg/non-veg choice above.
        for ($i = 0; $i < $fullCount; $i++) $foodArr[] = $foodChoice;
        for ($i = 0; $i < $concertCount; $i++) $foodArr[] = 'nodinner';
        for ($i = 0; $i < $children; $i++) $foodArr[] = $foodChoice;

        $allowedPay = ['cash', 'zelle', 'check', 'card', 'sulekha'];
        $paymentMethod = (isset($_post->paymentMethod) && in_array($_post->paymentMethod, $allowedPay, true)) ? $_post->paymentMethod : 'cash';

        $now = date('Y-m-d H:i:s');
        $paymentReference = "Walk-in Quick Add at gate, confirmed by {$station}, {$now}";

        // Pad to $qty entries (buyer's name in slot 1, rest blank) -- attendeeSummary() iterates
        // attendee_names to build the per-person check-in checklist, so a shorter array here
        // would silently hide checkboxes for the other people in the party.
        $attendeeNames = array_fill(0, $qty, '');
        $attendeeNames[0] = $name;

        $ins = $db->prepare(
            "INSERT INTO tb_registrations
                (date, name, email, phone, city, qty, unit_price, total_amount,
                 adults, kids_6to12, children, attendee_names, food_pref, days_selection, pass_type, is_student,
                 email_updates, whatsapp_updates, sms_updates, heard_from,
                 payment_method, payment_reference, status, ipaddress)
             VALUES
                (:date, :name, :email, :phone, '', :qty, :unit_price, :total,
                 :adults, :kids6to12, :children, :attendee_names, :food_pref, :day, :pass_type, :is_student,
                 0, 0, 0, 'walk-in',
                 :payment_method, :payment_reference, 'paid', :ip)"
        );
        $ins->execute([
            ':date' => $now, ':name' => $name, ':email' => $email, ':phone' => $phone,
            ':qty' => $qty, ':unit_price' => round($total / $qty, 2), ':total' => $total,
            ':adults' => $adults, ':kids6to12' => $kids6to12, ':children' => $children,
            ':attendee_names' => json_encode($attendeeNames), ':food_pref' => json_encode($foodArr),
            ':day' => $day, ':pass_type' => $passType, ':is_student' => $isStudent,
            ':payment_method' => $paymentMethod, ':payment_reference' => $paymentReference,
            ':ip' => get_client_ip(),
        ]);

        $newId = (int)$db->lastInsertId();
        $sel = $db->prepare("SELECT * FROM tb_registrations WHERE id = :id");
        $sel->execute([':id' => $newId]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);

        // Which tab the volunteer is currently viewing (separate from $day above, the
        // purchased days_selection) -- just picks which day's check-in state the response shows.
        $viewDay = (isset($_post->viewDay) && $_post->viewDay === 'sunday') ? 'sunday' : 'saturday';
        echo json_encode(['ok' => true, 'id' => $newId, 'total' => $total, 'registration' => registrationPayload($row, $viewDay)]);
        break;
    }

    // Upgrade a Saturday-only registration to include Sunday too ($55/adult, $50/kid-or-student
    // price difference). `upgradeAdults`/`upgradeKids` say how many of the registration's people
    // are upgrading -- if that's everyone, the existing row is updated in place (whole-party
    // upgrade); if it's fewer, the registration is SPLIT: the original row keeps the remaining
    // Saturday-only people, and a new row is created for the upgraded subset (Both Days), same
    // split pattern already used manually for Kumarika Banerjee's group on 2026-10-14 -- this
    // keeps per-person accuracy (who actually paid for which days) and an audit trail.
    case 'upgradeDay': {
        $station = mb_substr(isset($_post->station) ? (string)$_post->station : 'Front Gate', 0, 100);
        $id = (int)(isset($_post->id) ? $_post->id : 0);
        if ($id <= 0) { echo json_encode(['err' => 'Missing registration id.']); break; }

        $sel = $db->prepare("SELECT * FROM tb_registrations WHERE id = :id");
        $sel->execute([':id' => $id]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['err' => 'Registration not found.']); break; }
        if ($row['status'] !== 'paid') { echo json_encode(['err' => 'Not marked as paid.']); break; }
        if ($row['days_selection'] !== 'saturday') { echo json_encode(['err' => 'Only a Saturday-only registration can be upgraded to Both Days.']); break; }

        $curAdults = (int)$row['adults'];
        $curKids   = (int)$row['kids_6to12'];
        $upAdults  = max(0, min($curAdults, (int)(isset($_post->upgradeAdults) ? $_post->upgradeAdults : 0)));
        $upKids    = max(0, min($curKids,   (int)(isset($_post->upgradeKids)   ? $_post->upgradeKids   : 0)));
        $upQty = $upAdults + $upKids;
        if ($upQty <= 0) { echo json_encode(['err' => 'Select at least one person to upgrade.']); break; }

        $UPGRADE_ADULT = 55; $UPGRADE_KID = 35;
        $upgradeFee = round($upAdults * $UPGRADE_ADULT + $upKids * $UPGRADE_KID, 2);

        // Manual override for cases the flat per-person rate doesn't cleanly cover -- this
        // records an amount the desk already collected by cash/zelle/check/card, not a live
        // charge. A PayPal upgrade is a separate, verified-capture flow -- see gate-pay.php --
        // never a bare tag here, since nothing here confirms real money actually moved.
        if (isset($_post->customFee) && is_numeric($_post->customFee) && (float)$_post->customFee > 0) {
            $upgradeFee = round((float)$_post->customFee, 2);
        }

        $allowedPay = ['cash', 'zelle', 'check', 'card'];
        $paymentMethod = (isset($_post->paymentMethod) && in_array($_post->paymentMethod, $allowedPay, true)) ? $_post->paymentMethod : $row['payment_method'];

        $db->beginTransaction();
        try {
            $result = applyDayUpgrade($db, $row, $upAdults, $upKids, $upgradeFee, $paymentMethod, $station);
            $db->commit();
        } catch (\PDOException $e) {
            $db->rollBack();
            echo json_encode(['err' => 'A server error occurred during the upgrade. Nothing was changed.']);
            break;
        }

        if ($result['mode'] === 'whole') {
            $sel->execute([':id' => $result['id']]);
            $updatedRow = $sel->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'mode' => 'whole', 'upgradeFee' => $upgradeFee, 'registration' => registrationPayload($updatedRow, 'saturday')]);
        } else {
            $sel->execute([':id' => $result['remainingId']]);
            $stayRow = $sel->fetch(PDO::FETCH_ASSOC);
            $sel->execute([':id' => $result['upgradedId']]);
            $newRow = $sel->fetch(PDO::FETCH_ASSOC);
            echo json_encode([
                'ok' => true, 'mode' => 'split', 'upgradeFee' => $upgradeFee, 'newId' => $result['upgradedId'],
                'remaining' => registrationPayload($stayRow, 'saturday'),
                'upgraded'  => registrationPayload($newRow, 'saturday'),
            ]);
        }
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

    // Dinner vs. no-dinner headcount breakdown per day -- same qty-based, day-applicable
    // counting as 'list' above (never adults+kids6to12+children), just split by food_pref.
    case 'foodSummary': {
        $stmt = $db->query("SELECT qty, days_selection, food_pref FROM tb_registrations WHERE status='paid'");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $summary = [];
        foreach (['saturday', 'sunday'] as $day) {
            $veg = 0; $nonveg = 0; $noDinner = 0;
            foreach ($rows as $r) {
                if (!dayApplies($r['days_selection'], $day)) continue;
                $qty = (int)$r['qty'];
                $foodArr = json_decode($r['food_pref'], true);
                if (!is_array($foodArr)) $foodArr = [$r['food_pref']];
                for ($i = 0; $i < $qty; $i++) {
                    $food = isset($foodArr[$i]) ? $foodArr[$i] : 'nonvegetarian';
                    if ($food === 'vegetarian') $veg++;
                    elseif ($food === 'nodinner') $noDinner++;
                    else $nonveg++;
                }
            }
            $summary[$day] = [
                'vegetarian'    => $veg,
                'nonVegetarian' => $nonveg,
                'dinner'        => $veg + $nonveg,
                'noDinner'      => $noDinner,
                'total'         => $veg + $nonveg + $noDinner,
            ];
        }
        echo json_encode(['ok' => true, 'summary' => $summary]);
        break;
    }

    // Full roster for the browsable/searchable list — every paid registration, lightweight
    // fields only (full per-attendee details are fetched fresh via 'lookup' when a row is
    // opened). Returns BOTH days' independent counts per row so the frontend can show
    // whichever day's tab is active without a second round trip on every tab switch.
    case 'roster': {
        $stmt = $db->query(
            "SELECT id, name, qty, days_selection, checked_in_attendees_sat, checked_in_attendees_sun, gift_given, payment_method, phone, referred_by, email
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
                'id'            => (int)$r['id'],
                'name'          => $r['name'],
                'qty'           => $qty,
                'days'          => isset($daysMap[$r['days_selection']]) ? $daysMap[$r['days_selection']] : 'Both Days',
                'sat'           => ['checkedInCount' => $satIn, 'checkInStatus' => $mk($satIn, $qty)],
                'sun'           => ['checkedInCount' => $sunIn, 'checkInStatus' => $mk($sunIn, $qty)],
                'giftGiven'     => !empty($r['gift_given']),
                'paymentSource' => paymentSource($r),
                'phoneLast4'    => phoneLast4($r['phone']),
                'referredBy'    => isset($r['referred_by']) ? $r['referred_by'] : '',
                // Full email, not just last-4-like redaction -- used client-side purely to
                // detect "same person, multiple bookings" (matching name + email) so the
                // roster can visually cluster them; never displayed on screen as-is.
                'email'         => isset($r['email']) ? $r['email'] : '',
            ];
        }, $rows);
        echo json_encode(['ok' => true, 'roster' => $roster]);
        break;
    }

    // Flat, print-ready export — every paid registration with full phone, per-attendee food
    // breakdown, payment method and total paid. Read-only; same exposure level as 'roster'
    // (full phone) plus total_amount, used only for the offline backup check-in list.
    case 'exportList': {
        $stmt = $db->query(
            "SELECT id, name, phone, days_selection, qty, adults, kids_6to12, children, food_pref, payment_method, total_amount
             FROM tb_registrations WHERE status='paid' ORDER BY name COLLATE NOCASE ASC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $foodTag = ['vegetarian' => 'V', 'nonvegetarian' => 'NV', 'nodinner' => 'ND'];
        $out = array_map(function($r) use ($foodTag) {
            $qty = (int)$r['qty'];
            $foodArr = json_decode($r['food_pref'], true);
            if (!is_array($foodArr)) $foodArr = [$r['food_pref']];
            $counts = ['V' => 0, 'NV' => 0, 'ND' => 0];
            for ($i = 0; $i < $qty; $i++) {
                $f = isset($foodArr[$i]) ? $foodArr[$i] : 'nonvegetarian';
                $tag = isset($foodTag[$f]) ? $foodTag[$f] : 'NV';
                $counts[$tag]++;
            }
            return [
                'id'        => (int)$r['id'],
                'name'      => $r['name'],
                'phone'     => isset($r['phone']) ? $r['phone'] : '',
                'days'      => $r['days_selection'],
                'qty'       => $qty,
                'adults'    => (int)(isset($r['adults']) ? $r['adults'] : 0),
                'kids6to12' => (int)(isset($r['kids_6to12']) ? $r['kids_6to12'] : 0),
                'children'  => (int)(isset($r['children']) ? $r['children'] : 0),
                'food'      => $counts,
                'payment'   => paymentSource($r),
                'total'     => number_format((float)$r['total_amount'], 2),
            ];
        }, $rows);
        echo json_encode(['ok' => true, 'rows' => $out]);
        break;
    }

    default:
        echo json_encode(['err' => 'Unknown action']);
}
