<?php
namespace UtsovAPI;
use PDO;
require(dirname(__FILE__).'/utils.php');
require(dirname(__FILE__).'/checkout-config.php');
header('Content-Type: application/json');

// Step 1: basic
$out = array('step'=>0,'php'=>phpversion());

// Step 2: DB
try {
    $db = new PDO("sqlite:" . getDBPath("register"));
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $out['step'] = 1;
    $out['db'] = 'ok';
} catch (Exception $e) {
    $out['db_err'] = $e->getMessage();
    echo json_encode($out); exit;
}

// Step 3: json_decode of POST
$raw = file_get_contents("php://input");
$post = json_decode($raw);
$out['step'] = 2;
$out['post_ok'] = ($post !== null);
$out['action'] = isset($post->action) ? $post->action : 'none';

// Step 4: PayPal token
$ch = curl_init(PAYPAL_API_BASE . '/v1/oauth2/token');
curl_setopt_array($ch, array(
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
    CURLOPT_USERPWD        => PAYPAL_CLIENT_ID . ':' . PAYPAL_SECRET,
    CURLOPT_HTTPHEADER     => array('Accept: application/json', 'Accept-Language: en_US'),
    CURLOPT_TIMEOUT        => 20,
));
$body = curl_exec($ch);
$errno = curl_errno($ch);
curl_close($ch);
$out['step'] = 3;
$out['pp_errno'] = $errno;
$data = json_decode($body);
$out['pp_token'] = isset($data->access_token) ? 'ok' : 'fail';

echo json_encode($out);
?>
