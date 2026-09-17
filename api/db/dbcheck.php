<?php
namespace UtsovAPI;
use PDO;
require(dirname(__FILE__)."/../utils.php");
header("Content-Type: application/json");
if (!isset($_GET["k"]) || $_GET["k"] !== "utsov2026diag") { http_response_code(403); exit; }
$out = array("reg" => array(), "don" => array());

// Registration DB - last 5
try {
    $db = new PDO("sqlite:" . getDBPath("register"));
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $rows = $db->query("SELECT id,date,updated_date,name,email,phone,qty,total_amount,status,paypal_order_id,paypal_capture_id,patron_qty,student_qty,kids_qty FROM tb_registrations ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    $out["reg"] = $rows;
} catch (Exception $e) {
    // Try without new columns
    try {
        $db2 = new PDO("sqlite:" . getDBPath("register"));
        $rows2 = $db2->query("SELECT id,date,updated_date,name,email,phone,qty,total_amount,status,paypal_order_id,paypal_capture_id FROM tb_registrations ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        $out["reg"] = $rows2;
        $out["reg_err"] = $e->getMessage();
    } catch (Exception $e2) { $out["reg_err"] = $e2->getMessage(); }
}

// Donation DB - last 5
try {
    $db3 = new PDO("sqlite:" . getDBPath("donation"));
    $db3->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $rows3 = $db3->query("SELECT id,date,updated_date,name,email,amount,status,paypal_order_id,paypal_capture_id FROM tb_donations ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    $out["don"] = $rows3;
} catch (Exception $e) {
    $out["don_err"] = $e->getMessage();
}

echo json_encode($out, JSON_PRETTY_PRINT);
?>