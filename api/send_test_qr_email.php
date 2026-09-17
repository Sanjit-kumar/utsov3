<?php
// ONE-OFF ADMIN TOOL — generates check-in QR codes for the two TEST registrations and
// emails them (as inline base64 images, no external hosting needed) to skdas8075@gmail.com
// for scanner testing. Delete this file after use.
namespace UtsovAPI;
require(dirname(__FILE__).'/checkin-config.php');
require(dirname(__FILE__).'/checkin-lib.php');
require(dirname(__FILE__).'/qrcode-lib.php');
header('Content-Type: text/plain');

// ids passed via query string by the caller, from insert_test_regs.php's output
$id100 = isset($_GET['id100']) ? (int)$_GET['id100'] : 0;
$id200 = isset($_GET['id200']) ? (int)$_GET['id200'] : 0;
if ($id100 <= 0 || $id200 <= 0) { echo "Usage: ?id100=<id>&id200=<id>\n"; exit; }

// Hosts the QR as a real file under api/email-images/ and returns its public URL —
// same proven pattern buildEmailImage() already uses for confirmation emails. An earlier
// attempt embedded the PNG as an inline base64 data: URI directly in the HTML; that put
// several KB of unbroken base64 text on a single line, which violates standard email
// line-length limits and got silently dropped somewhere in the relay chain (never arrived,
// not even in spam). A hosted URL avoids that entirely — same reason real ticket emails work.
function qrPngUrl($token, $outKey) {
    $qr = new \QRCode($token, ['w' => 280, 'h' => 280]);
    $img = $qr->render_image();

    $outDir = dirname(__FILE__) . '/email-images/';
    if (!is_dir($outDir)) mkdir($outDir, 0755, true);

    $safe  = preg_replace('/[^A-Za-z0-9]/', '', $outKey);
    $fname = 'qr_' . substr($safe, 0, 28) . '_' . substr(md5($outKey), 0, 6) . '.png';
    imagepng($img, $outDir . $fname, 7);
    imagedestroy($img);

    return 'https://utsov.org/api/email-images/' . $fname;
}

$token100 = generateCheckinToken($id100);
$token200 = generateCheckinToken($id200);
$qr100 = qrPngUrl($token100, 'test100_' . $id100);
$qr200 = qrPngUrl($token200, 'test200_' . $id200);

$to = 'skdas8075@gmail.com';
$subject = '[TEST] UTSOV Check-In QR Codes — test100 (paid) + test200 (not paid)';
$mailHeaders = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: noreply@utsov.org\r\n";

$body = "<html><body style='font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto;padding:20px'>"
  . "<h2 style='color:#8B1A1A'>Check-In QR Test Codes</h2>"
  . "<p>These are TEST registrations for scanner testing only.</p>"

  . "<div style='border:2px solid #1F8A3B;border-radius:12px;padding:16px;margin:16px 0'>"
  . "<h3 style='color:#1F8A3B'>TEST100 — id={$id100} — PAID</h3>"
  . "<p>Expected: scanner should show <b>green / PAID &amp; VERIFIED</b> and allow check-in.</p>"
  . "<img src='{$qr100}' width='220' height='220' alt='TEST100 QR'>"
  . "<p style='font-size:11px;color:#888'>Token: {$token100}</p>"
  . "</div>"

  . "<div style='border:2px solid #9E2424;border-radius:12px;padding:16px;margin:16px 0'>"
  . "<h3 style='color:#9E2424'>TEST200 — id={$id200} — NOT PAID</h3>"
  . "<p>Expected: scanner should show <b>red / NOT PAID — DO NOT ADMIT</b> and block check-in.</p>"
  . "<img src='{$qr200}' width='220' height='220' alt='TEST200 QR'>"
  . "<p style='font-size:11px;color:#888'>Token: {$token200}</p>"
  . "</div>"

  . "</body></html>";

$ok = mail($to, $subject, $body, $mailHeaders);
echo "To: {$to} mail()=" . ($ok ? 'true' : 'false') . "\n";
echo "test100_id={$id100} token={$token100}\n";
echo "test200_id={$id200} token={$token200}\n";
