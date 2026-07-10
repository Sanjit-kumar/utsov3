<?php
namespace UtsovAPI;
use PDO;
require(dirname(__FILE__).'/utils.php');
require(dirname(__FILE__).'/checkout-config.php');
date_default_timezone_set('America/New_York');
header('Content-Type: application/json');

if (!isset($_GET['k']) || $_GET['k'] !== 'utsov2026diag') {
    http_response_code(403); echo json_encode(['err'=>'Forbidden']); exit;
}

$out = [];

// ── 1. PHP + CURL basics ──────────────────────────────────────────────────────
$out['php_version']    = phpversion();
$out['curl_available'] = function_exists('curl_version');
$out['curl_version']   = function_exists('curl_version') ? curl_version()['version'] : null;
$out['paypal_mode']    = PAYPAL_MODE;
$out['paypal_base']    = PAYPAL_API_BASE;

// ── 2. DB path + permissions ──────────────────────────────────────────────────
$regDbPath  = getDBPath('register');
$captDbPath = getDBPath('capture');
$out['db_register_path']    = $regDbPath;
$out['db_register_dir_ok']  = is_writable(dirname($regDbPath));
$out['db_register_file_ok'] = file_exists($regDbPath);
$out['db_capture_path']     = $captDbPath;
$out['db_capture_dir_ok']   = is_writable(dirname($captDbPath));
$out['db_capture_file_ok']  = file_exists($captDbPath);

// ── 3. DB open test ───────────────────────────────────────────────────────────
try {
    $db = new PDO("sqlite:" . $regDbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA busy_timeout=3000");
    $count = $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='tb_registrations'")->fetchColumn();
    $out['db_register_open'] = true;
    $out['db_table_exists']  = (int)$count > 0;
    if ((int)$count > 0) {
        $out['db_row_count'] = (int)$db->query("SELECT COUNT(*) FROM tb_registrations")->fetchColumn();
        $latest = $db->query("SELECT name,email,status,total_amount,date FROM tb_registrations ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $out['db_latest_row'] = $latest ?: null;
    }
} catch (\Exception $e) {
    $out['db_register_open'] = false;
    $out['db_open_error']    = $e->getMessage();
}

// ── 4. PayPal token ───────────────────────────────────────────────────────────
$ch = curl_init(PAYPAL_API_BASE . '/v1/oauth2/token');
curl_setopt_array($ch, [
    CURLOPT_SSL_VERIFYPEER  => false,
    CURLOPT_SSL_VERIFYHOST  => false,
    CURLOPT_RETURNTRANSFER  => true,
    CURLOPT_POST            => true,
    CURLOPT_POSTFIELDS      => 'grant_type=client_credentials',
    CURLOPT_USERPWD         => PAYPAL_CLIENT_ID . ':' . PAYPAL_SECRET,
    CURLOPT_HTTPHEADER      => ['Accept: application/json','Accept-Language: en_US'],
    CURLOPT_TIMEOUT         => 20,
    CURLOPT_CONNECTTIMEOUT  => 10,
]);
$ppBody  = curl_exec($ch);
$ppErrno = curl_errno($ch);
$ppError = curl_error($ch);
$ppHttp  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$out['pp_curl_errno'] = $ppErrno;
$out['pp_curl_error'] = $ppError ?: null;
$out['pp_http_code']  = $ppHttp;
$out['pp_response_raw'] = $ppBody ? substr($ppBody, 0, 400) : null;
$ppData = json_decode($ppBody);
$token  = isset($ppData->access_token) ? $ppData->access_token : null;
$out['pp_token_ok'] = (bool)$token;
$out['pp_token_type'] = isset($ppData->token_type) ? $ppData->token_type : null;
$out['pp_error_name'] = isset($ppData->name) ? $ppData->name : null;

// ── 5. If token OK, create a tiny $1 test order ───────────────────────────────
if ($token) {
    $payload = json_encode([
        'intent'         => 'CAPTURE',
        'purchase_units' => [['description'=>'UTSOV test','amount'=>['currency_code'=>'USD','value'=>'1.00']]],
    ]);
    $ch2 = curl_init(PAYPAL_API_BASE . '/v2/checkout/orders');
    curl_setopt_array($ch2, [
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json','Authorization: Bearer '.$token],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $ordBody = curl_exec($ch2);
    $ordHttp = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
    $ordData = json_decode($ordBody);
    $out['pp_order_http']    = $ordHttp;
    $out['pp_order_id']      = isset($ordData->id)     ? $ordData->id     : null;
    $out['pp_order_status']  = isset($ordData->status) ? $ordData->status : null;
    $out['pp_order_error']   = isset($ordData->name)   ? $ordData->name   : null;
    $out['pp_order_raw']     = $ordBody ? substr($ordBody, 0, 300) : null;
}

echo json_encode($out, JSON_PRETTY_PRINT);
?>
