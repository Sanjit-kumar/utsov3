<?php
// Order verification endpoint for Playwright payment tests.
// No namespace - standalone script that opens the SQLite files directly.
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

define('VERIFY_SECRET', 'UtsovTest2026!');
define('DB_DIR', __DIR__ . '/db/');

$secret = isset($_GET['secret']) ? $_GET['secret'] : '';
if ($secret !== VERIFY_SECRET) {
    http_response_code(401);
    echo json_encode(array('err' => 'Unauthorized'));
    exit;
}

if (isset($_GET['ping'])) {
    $regExists = file_exists(DB_DIR . 'registration.db');
    $capExists = file_exists(DB_DIR . 'capture.db');
    echo json_encode(array(
        'ok'              => true,
        'ts'              => date('Y-m-d H:i:s'),
        'registration_db' => $regExists ? 'found' : 'not found',
        'capture_db'      => $capExists ? 'found' : 'not found',
    ));
    exit;
}

$type    = isset($_GET['type'])    ? trim($_GET['type'])    : '';
$orderId = isset($_GET['orderId']) ? trim($_GET['orderId']) : '';
$n       = min(100, max(1, isset($_GET['n']) ? (int)$_GET['n'] : 10));

function openDB($path) {
    if (!file_exists($path)) return null;
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout=3000');
    return $db;
}

// food_pref is stored as a JSON array (one entry per attendee) but legacy
// rows may still hold a plain string; normalize to a comma-joined string.
function foodPrefFlat($raw) {
    if ($raw === null || $raw === '') return '';
    $arr = json_decode($raw, true);
    if (!is_array($arr)) { $arr = array($raw); }
    return implode(',', $arr);
}

// Look up a specific PayPal order ID
if ($orderId) {
    $row = null;

    // Check bookings (registration.db -> tb_registrations)
    $db = openDB(DB_DIR . 'registration.db');
    if ($db) {
        try {
            $st = $db->prepare('SELECT * FROM tb_registrations WHERE paypal_order_id = ? LIMIT 1');
            $st->execute(array($orderId));
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $row = array(
                    'found'      => true,
                    'source'     => 'booking',
                    'orderId'    => $orderId,
                    'status'     => $r['status'],
                    'email'      => $r['email'],
                    'name'       => $r['name'],
                    'amount'     => (float)$r['total_amount'],
                    'qty'        => (int)$r['qty'],
                    'days'       => isset($r['days_selection']) ? $r['days_selection'] : '',
                    'food'       => isset($r['food_pref']) ? foodPrefFlat($r['food_pref']) : '',
                    'is_student' => !empty($r['is_student']),
                    'captureId'  => isset($r['paypal_capture_id']) ? $r['paypal_capture_id'] : '',
                    'date'       => $r['date'],
                    'updated'    => isset($r['updated_date']) ? $r['updated_date'] : '',
                );
            }
        } catch (Exception $e) { /* continue */ }
    }

    // Check donations (capture.db -> tb_donations)
    if (!$row) {
        $db2 = openDB(DB_DIR . 'capture.db');
        if ($db2) {
            try {
                $st = $db2->prepare('SELECT * FROM tb_donations WHERE paypal_order_id = ? LIMIT 1');
                $st->execute(array($orderId));
                $r = $st->fetch(PDO::FETCH_ASSOC);
                if ($r) {
                    $row = array(
                        'found'     => true,
                        'source'    => 'donation',
                        'orderId'   => $orderId,
                        'status'    => $r['status'],
                        'email'     => $r['email'],
                        'name'      => $r['name'],
                        'amount'    => (float)$r['amount'],
                        'captureId' => isset($r['paypal_capture_id']) ? $r['paypal_capture_id'] : '',
                        'date'      => $r['date'],
                        'updated'   => isset($r['updated_date']) ? $r['updated_date'] : '',
                    );
                }
            } catch (Exception $e) { /* not found */ }
        }
    }

    echo json_encode($row ? $row : array('found' => false, 'orderId' => $orderId));
    exit;
}

// Recent donations
if ($type === 'donations') {
    $db = openDB(DB_DIR . 'capture.db');
    if (!$db) { echo json_encode(array()); exit; }
    try {
        $st = $db->query("SELECT paypal_order_id AS orderId, email, name, amount, status, date
                          FROM tb_donations ORDER BY id DESC LIMIT " . $n);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        echo json_encode(array('err' => $e->getMessage()));
    }
    exit;
}

// Default: recent bookings
$db = openDB(DB_DIR . 'registration.db');
if (!$db) {
    echo json_encode(array());
    exit;
}
try {
    $st = $db->query("SELECT paypal_order_id AS orderId, email, name, total_amount AS amount,
                             qty, days_selection AS days, status, date
                      FROM tb_registrations ORDER BY id DESC LIMIT " . $n);
    echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo json_encode(array('err' => $e->getMessage()));
}
