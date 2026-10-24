<?php
namespace UtsovAPI;
use PDO;
require(dirname(__FILE__).'/utils.php');
require(dirname(__FILE__).'/email-builder.php');
require(dirname(__FILE__).'/twilio-config.php');
require(dirname(__FILE__).'/sms-sender.php');
date_default_timezone_set('America/New_York');

header('Content-Type: text/plain');

// Reusable resend-with-apology utility. Usage: resend_with_apology.php?id=<registration id>
// Intended as a temporary, admin-only tool - upload, use for a batch of cases, delete when done.

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { echo "Usage: ?id=<registration id>\n"; exit; }

$db = new PDO("sqlite:" . getDBPath("register"));
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sel = $db->prepare("SELECT * FROM tb_registrations WHERE id = :id");
$sel->execute([':id' => $id]);
$row = $sel->fetch(PDO::FETCH_ASSOC);

if (!$row) { echo "id={$id}: Row not found\n"; exit; }

// For manual entries (Zelle/Sulekha/donor) there's no paypal_order_id, and payment_reference
// is an internal admin note (can contain $ amounts, long free text) -- never show that to the
// customer as their "Order ID". Use a clean synthetic reference instead in that case.
$orderId = $row['paypal_order_id'] ?: ('UTSOV-' . str_pad($id, 5, '0', STR_PAD_LEFT));
$qty     = (int)$row['qty'];
$total   = number_format((float)$row['total_amount'], 2);
$name    = $row['name'];
$email   = $row['email'];

$daysMap  = ['both' => 'Both Days (Oct 17 &amp; 18)', 'saturday' => 'Saturday, Oct 17', 'sunday' => 'Sunday, Oct 18'];
$foodMap  = ['vegetarian' => 'Vegetarian', 'nonvegetarian' => 'Non-Vegetarian', 'nodinner' => 'No Dinner'];
$daysDisp = isset($daysMap[$row['days_selection']]) ? $daysMap[$row['days_selection']] : 'Both Days';

$foodArr = json_decode($row['food_pref'], true);
if (!is_array($foodArr)) { $foodArr = [$row['food_pref']]; }
$foodArr = array_map(function($f) use ($foodMap) {
    return isset($foodMap[$f]) ? $foodMap[$f] : 'Non-Vegetarian';
}, $foodArr);
$vegCount = count(array_filter($foodArr, function($f){ return $f === 'Vegetarian'; }));
$noDinnerCount = count(array_filter($foodArr, function($f){ return $f === 'No Dinner'; }));
$nonVegCount = count($foodArr) - $vegCount - $noDinnerCount;
$foodSummaryParts = [];
if ($nonVegCount > 0) $foodSummaryParts[] = "{$nonVegCount} Non-Veg";
if ($vegCount > 0) $foodSummaryParts[] = "{$vegCount} Veg";
if ($noDinnerCount > 0) $foodSummaryParts[] = "{$noDinnerCount} No Dinner (Concert Only)";
$foodDisp = $foodSummaryParts ? implode(', ', $foodSummaryParts) : 'Non-Vegetarian';

$adultsDisp = isset($row['adults'])   ? (int)$row['adults']   : $qty;
$childDisp  = isset($row['children']) ? (int)$row['children'] : 0;
$passWord   = $qty > 1 ? 'passes' : 'pass';
$guestLine  = $adultsDisp . ' adult' . ($adultsDisp != 1 ? 's' : '')
            . ($childDisp > 0 ? ' + ' . $childDisp . ' child' . ($childDisp > 1 ? 'ren' : '') : '');

$attendeeNames = json_decode($row['attendee_names'], true);
$attendeeDisp = is_array($attendeeNames) ? implode(', ', array_filter($attendeeNames)) : '';

$safeName  = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$safeOrder = htmlspecialchars($orderId, ENT_QUOTES, 'UTF-8');

$subject = "Your UTSOV 2026 Passes (Resent) - Our Apologies for the Delay";
$mailHeaders = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: noreply@utsov.org\r\n"
             . "Cc: skdas8075@gmail.com, utsov@utsov.org\r\n";

// Full details always rendered as real HTML text (not just inside an image), since we've
// confirmed some email clients block remote images by default - this way the pass details
// are visible even if images never load.
$body = "<html><body style='font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto;padding:20px'>"
  . "<h2 style='color:#8B1A1A'>We're sorry for the delay, {$safeName}!</h2>"
  . "<p>It looks like you may not have received your original UTSOV 2026 pass confirmation. We apologize for the inconvenience - here are your full booking details again below.</p>"
  . "<table style='border-collapse:collapse;font-size:14px;width:100%;margin:16px 0'>"
  . "<tr><td style='padding:6px 16px 6px 0;border-bottom:1px solid #eee'><b>Passes:</b></td><td style='padding:6px 0;border-bottom:1px solid #eee'>UTSOV 2026 x {$qty} {$passWord}</td></tr>"
  . "<tr><td style='padding:6px 16px 6px 0;border-bottom:1px solid #eee'><b>Days:</b></td><td style='padding:6px 0;border-bottom:1px solid #eee'>{$daysDisp}</td></tr>"
  . "<tr><td style='padding:6px 16px 6px 0;border-bottom:1px solid #eee'><b>Attendees:</b></td><td style='padding:6px 0;border-bottom:1px solid #eee'>{$guestLine}" . ($attendeeDisp ? " ({$attendeeDisp})" : "") . "</td></tr>"
  . "<tr><td style='padding:6px 16px 6px 0;border-bottom:1px solid #eee'><b>Meal Preference:</b></td><td style='padding:6px 0;border-bottom:1px solid #eee'>{$foodDisp}</td></tr>"
  . "<tr><td style='padding:6px 16px 6px 0'><b>Order ID:</b></td><td style='padding:6px 0'>{$safeOrder}</td></tr>"
  . "</table>"
  . "<p>Please keep this email as your ticket confirmation - no further action is needed. If you have any questions, just reply to this email or reach us at utsov@utsov.org.</p>"
  . "<p style='color:#8B1A1A'>Thank you for your patience, and see you at UTSOV 2026!<br>-- The UTSOV Team</p>"
  . "</body></html>";

$ok = mail($email, $subject, $body, $mailHeaders);

// SMS resend — only if this registrant opted in ("Text me my registration confirmation")
$smsOk = null;
if (!empty($row['sms_updates'])) {
    $smsDaysPlain = html_entity_decode($daysDisp, ENT_QUOTES, 'UTF-8');
    $smsBody = "UTSOV 2026: Sorry for the delay! Your {$qty} {$passWord} confirmed. {$smsDaysPlain}. Order {$orderId}. Reply STOP to opt out.";
    $smsOk = sendTwilioSMS($row['phone'], $smsBody);
}

echo "id={$id} To: {$email} Name: {$name} Total: \${$total} mail()=" . ($ok ? 'true' : 'false')
   . " sms()=" . ($smsOk === null ? 'skipped (not opted in)' : ($smsOk ? 'true' : 'false')) . "\n";
