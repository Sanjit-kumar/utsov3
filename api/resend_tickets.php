<?php
namespace UtsovAPI;
use PDO;
require(dirname(__FILE__).'/utils.php');
require(dirname(__FILE__).'/email-builder.php');
require(dirname(__FILE__).'/twilio-config.php');
require(dirname(__FILE__).'/sms-sender.php');
date_default_timezone_set('America/New_York');

header('Content-Type: text/plain');

// Reusable standard-ticket-confirmation resend. Usage: resend_tickets.php?id=<registration id>
// This is the SAME template checkout.php sends on successful payment (personalized image +
// plain-HTML fallback), used as-is for re-sending pass confirmations. Pair with
// resend_with_apology.php for the standard "resend + apology" two-email process.

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { echo "Usage: ?id=<registration id>\n"; exit; }

$db = new PDO("sqlite:" . getDBPath("register"));
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sel = $db->prepare("SELECT * FROM tb_registrations WHERE id = :id");
$sel->execute([':id' => $id]);
$row = $sel->fetch(PDO::FETCH_ASSOC);

if (!$row) { echo "id={$id}: Row not found\n"; exit; }

$orderId = $row['paypal_order_id'] ?: $row['payment_reference'];
$qty   = (int)$row['qty'];
$total = number_format((float)$row['total_amount'], 2);
$name  = $row['name'];
$email = $row['email'];

$daysMap    = ['both' => 'Both Days (Oct 17 &amp; 18)', 'saturday' => 'Saturday, Oct 17', 'sunday' => 'Sunday, Oct 18'];
$foodMap    = ['vegetarian' => 'Vegetarian', 'nonvegetarian' => 'Non-Vegetarian', 'nodinner' => 'No Dinner'];
$daysDisp   = isset($daysMap[$row['days_selection']]) ? $daysMap[$row['days_selection']] : 'Both Days';

$foodArr = json_decode($row['food_pref'], true);
if (!is_array($foodArr)) { $foodArr = [$row['food_pref']]; }
$foodArr = array_map(function($f) use ($foodMap) {
    return isset($foodMap[$f]) ? $foodMap[$f] : 'Non-Vegetarian';
}, $foodArr);
$vegCount = count(array_filter($foodArr, function($f){ return $f === 'Vegetarian'; }));
$nonVegCount = count($foodArr) - $vegCount;
$foodSummaryParts = [];
if ($nonVegCount > 0) $foodSummaryParts[] = "{$nonVegCount} Non-Veg";
if ($vegCount > 0) $foodSummaryParts[] = "{$vegCount} Veg";
$foodDisp = $foodSummaryParts ? implode(', ', $foodSummaryParts) : 'Non-Vegetarian';

$adultsDisp = isset($row['adults'])   ? (int)$row['adults']   : $qty;
$childDisp  = isset($row['children']) ? (int)$row['children'] : 0;

$safeName   = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$safeOrder  = htmlspecialchars($orderId, ENT_QUOTES, 'UTF-8');

$passWord   = $qty > 1 ? 'passes' : 'pass';
$guestLine  = $adultsDisp . ' adult' . ($adultsDisp != 1 ? 's' : '')
            . ($childDisp > 0 ? ' + ' . $childDisp . ' child' . ($childDisp > 1 ? 'ren' : '') : '');

$buyerSubject = "Your UTSOV 2026 passes are confirmed!";
$mailHeaders  = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: noreply@utsov.org\r\n"
              . "Cc: skdas8075@gmail.com, utsov@utsov.org\r\n";

$tRows = array(
    array('Passes',          imgText("UTSOV 2026 x {$qty} {$passWord}"), false, false, false),
    array('Days',            imgText($daysDisp),                          true,  false, false),
    array('Adults',          imgText($guestLine),                         false, false, false),
    array('Meal Preference', imgText($foodDisp),                          true,  false, false),
);
if (!empty($row['is_student'])) {
    $tRows[] = array('Pass Type', 'Student in group (bring student ID)', false, false, false);
}
$tRows[] = array('Total Paid', "\${$total}", true, true,  false);
$tRows[] = array('Order ID',   $orderId,     false, false, true);

$imgUrl = buildEmailImage(
    'BOOKING CONFIRMED', false,
    "You're going to UTSOV 2026!",
    array("Dear {$name},", "Your payment of \${$total} has been received.", "Your {$qty} {$passWord} are confirmed!"),
    'BOOKING DETAILS',
    $tRows,
    array('Full schedule & venue details coming closer to', 'October 17-18, New Jersey. See you there!'),
    $orderId
);

if ($imgUrl) {
    $buyerBody = "<html><body style='margin:0;padding:20px 0;background-color:#0d0000;text-align:center;font-family:Arial,sans-serif;'>"
      . "<img src='{$imgUrl}' width='600' alt='UTSOV 2026 Booking Confirmed' style='display:block;margin:0 auto;max-width:100%;border:0;'>"
      . "<p style='color:#888;font-size:11px;margin:8px auto 0;max-width:600px;'>"
      . "Booking confirmed for {$safeName} | \${$total} | Order: {$safeOrder}"
      . "</p></body></html>";
} else {
    $buyerBody = "<html><body style='font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto'>"
      . "<h2 style='color:#8B1A1A'>You're going to UTSOV 2026!</h2>"
      . "<p>Hi {$safeName},</p><p>Your payment of <b>\${$total}</b> has been received.</p>"
      . "<table style='border-collapse:collapse;font-size:14px'>"
      . "<tr><td style='padding:4px 16px 4px 0'><b>Passes:</b></td><td>UTSOV 2026 x {$qty} {$passWord}</td></tr>"
      . "<tr><td style='padding:4px 16px 4px 0'><b>Days:</b></td><td>{$daysDisp}</td></tr>"
      . "<tr><td style='padding:4px 16px 4px 0'><b>Adults:</b></td><td>{$guestLine}</td></tr>"
      . "<tr><td style='padding:4px 16px 4px 0'><b>Meal:</b></td><td>{$foodDisp}</td></tr>"
      . "<tr><td style='padding:4px 16px 4px 0'><b>Total:</b></td><td>\${$total}</td></tr>"
      . "<tr><td style='padding:4px 16px 4px 0'><b>Order ID:</b></td><td>{$safeOrder}</td></tr>"
      . "</table>"
      . "<p style='color:#8B1A1A'>-- The UTSOV Team</p></body></html>";
}

$ok = mail($email, $buyerSubject, $buyerBody, $mailHeaders);

// SMS resend — only if this registrant opted in ("Text me my registration confirmation")
$smsOk = null;
if (!empty($row['sms_updates'])) {
    $smsDaysPlain = html_entity_decode($daysDisp, ENT_QUOTES, 'UTF-8');
    $smsBody = "UTSOV 2026: Your {$qty} {$passWord} confirmed! {$smsDaysPlain}. Total \${$total}. Order {$orderId}. Reply STOP to opt out.";
    $smsOk = sendTwilioSMS($row['phone'], $smsBody);
}

echo "id={$id} To: {$email} Name: {$name} Total: \${$total} mail()=" . ($ok ? 'true' : 'false')
   . " sms()=" . ($smsOk === null ? 'skipped (not opted in)' : ($smsOk ? 'true' : 'false')) . "\n";
