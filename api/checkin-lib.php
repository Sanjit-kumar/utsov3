<?php
namespace UtsovAPI;

/**
 * Builds the string encoded into a registrant's check-in QR code: their registration id,
 * signed with CHECKIN_SIGNING_SECRET so a forged QR (someone just guessing/incrementing an
 * order id) can never pass verification without knowing the secret.
 */
function generateCheckinToken($id) {
    $id = (int)$id;
    $sig = substr(hash_hmac('sha256', (string)$id, CHECKIN_SIGNING_SECRET), 0, 12);
    return "UTSOV-CHECKIN:{$id}.{$sig}";
}

/**
 * Verifies a scanned QR token. Returns the registration id (int) if the signature is valid,
 * or null if the token is malformed or forged/tampered.
 */
function verifyCheckinToken($token) {
    $token = trim((string)$token);
    $prefix = 'UTSOV-CHECKIN:';
    if (strpos($token, $prefix) !== 0) return null;
    $rest = substr($token, strlen($prefix));

    $dot = strrpos($rest, '.');
    if ($dot === false) return null;

    $idPart  = substr($rest, 0, $dot);
    $sigPart = substr($rest, $dot + 1);
    if (!ctype_digit($idPart) || $idPart === '') return null;

    $expected = substr(hash_hmac('sha256', $idPart, CHECKIN_SIGNING_SECRET), 0, 12);
    if (!hash_equals($expected, $sigPart)) return null;

    return (int)$idPart;
}

/**
 * Applies a Saturday -> Both Days upgrade to an already-fetched, already-validated paid
 * registration row. Shared by checkin.php's immediate (cash/zelle/check/card) upgrade path
 * and gate-pay.php's PayPal-capture path, so both go through the exact same whole-party /
 * split logic instead of two copies that could drift apart. Caller is responsible for all
 * validation before calling this (status='paid', days_selection='saturday', qty bounds) and
 * for wrapping PDOExceptions -- this function assumes a PDO instance, not a transaction of
 * its own when called from within a larger one (e.g. gate-pay.php's capture flow).
 *
 * Returns ['mode' => 'whole', 'id' => <int>] or ['mode' => 'split', 'remainingId' => <int>,
 * 'upgradedId' => <int>]. Throws \PDOException on a database error -- caller decides how to
 * surface that (checkin.php rolls back and reports; gate-pay.php must NOT apply a partial
 * upgrade after a real payment was already captured, so it logs and flags for manual review
 * instead of silently losing the money-to-registration link).
 */
function applyDayUpgrade($db, $row, $upAdults, $upKids, $upgradeFee, $paymentMethod, $station) {
    $id = (int)$row['id'];
    $now = date('Y-m-d H:i:s');
    $curAdults = (int)$row['adults'];
    $curKids   = (int)$row['kids_6to12'];
    $upQty     = $upAdults + $upKids;
    $wholeParty = ($upQty >= (int)$row['qty']);

    if ($wholeParty) {
        $newTotal = round((float)$row['total_amount'] + $upgradeFee, 2);
        $newRef = $row['payment_reference'] . " | UPGRADED to Both Days {$now} (+\${$upgradeFee}, {$upAdults}A/{$upKids}K, via {$paymentMethod}, confirmed by {$station})";
        $upd = $db->prepare("UPDATE tb_registrations SET days_selection='both', total_amount=:total, payment_reference=:ref WHERE id=:id");
        $upd->execute([':total' => $newTotal, ':ref' => $newRef, ':id' => $id]);
        return ['mode' => 'whole', 'id' => $id];
    }

    $remAdults = $curAdults - $upAdults;
    $remKids   = $curKids - $upKids;
    $remQty    = (int)$row['qty'] - $upQty;
    $origQty   = (int)$row['qty'];
    $unitPrice = (float)$row['unit_price'];

    $remNames = json_decode($row['attendee_names'], true);
    if (!is_array($remNames)) $remNames = [$row['name']];
    $remFoods = json_decode($row['food_pref'], true);
    if (!is_array($remFoods)) $remFoods = [$row['food_pref']];
    $remNames = array_pad($remNames, $origQty, '');
    $remFoods = array_pad($remFoods, $origQty, 'nonvegetarian');

    $upNames = array_slice($remNames, 0, $upQty);
    $upFoods = array_slice($remFoods, 0, $upQty);
    $stayNames = array_slice($remNames, $upQty);
    $stayFoods = array_slice($remFoods, $upQty);

    $remTotal = round($unitPrice * $remQty, 2);
    $upTotal  = round($unitPrice * $upQty + $upgradeFee, 2);

    $upd = $db->prepare(
        "UPDATE tb_registrations SET qty=:qty, adults=:adults, kids_6to12=:kids, total_amount=:total,
         attendee_names=:names, food_pref=:foods, payment_reference=:ref WHERE id=:id"
    );
    $upd->execute([
        ':qty' => $remQty, ':adults' => $remAdults, ':kids' => $remKids, ':total' => $remTotal,
        ':names' => json_encode(array_values($stayNames)), ':foods' => json_encode(array_values($stayFoods)),
        ':ref' => $row['payment_reference'] . " | SPLIT {$now}: {$upQty} of {$origQty} upgraded to Both Days (new registration), {$remQty} remain Saturday-only, confirmed by {$station}",
        ':id' => $id,
    ]);

    $newRef = "Split from id={$id} ({$row['name']}'s original Saturday-only registration) {$now}: " .
              "{$upAdults} adult(s) + {$upKids} kid(s) upgraded to Both Days (+\${$upgradeFee} upgrade fee), via {$paymentMethod}, confirmed by {$station}";
    $ins = $db->prepare(
        "INSERT INTO tb_registrations
            (date, name, email, phone, city, qty, unit_price, total_amount,
             adults, kids_6to12, children, attendee_names, food_pref, days_selection, pass_type, is_student,
             email_updates, whatsapp_updates, sms_updates, heard_from,
             payment_method, payment_reference, status, ipaddress, referred_by)
         VALUES
            (:date, :name, :email, :phone, :city, :qty, :unit_price, :total,
             :adults, :kids, 0, :names, :foods, 'both', :pass_type, :is_student,
             :email_updates, :whatsapp, :sms, :heard,
             :payment_method, :ref, 'paid', :ip, :referred_by)"
    );
    $ins->execute([
        ':date' => $now, ':name' => $row['name'], ':email' => $row['email'], ':phone' => $row['phone'], ':city' => $row['city'],
        ':qty' => $upQty, ':unit_price' => $unitPrice, ':total' => $upTotal,
        ':adults' => $upAdults, ':kids' => $upKids,
        ':names' => json_encode(array_values($upNames)), ':foods' => json_encode(array_values($upFoods)),
        ':pass_type' => $row['pass_type'], ':is_student' => $row['is_student'],
        ':email_updates' => $row['email_updates'], ':whatsapp' => $row['whatsapp_updates'], ':sms' => $row['sms_updates'], ':heard' => $row['heard_from'],
        ':payment_method' => $paymentMethod, ':ref' => $newRef, ':ip' => get_client_ip(), ':referred_by' => $row['referred_by'],
    ]);
    $newId = (int)$db->lastInsertId();

    return ['mode' => 'split', 'remainingId' => $id, 'upgradedId' => $newId];
}
