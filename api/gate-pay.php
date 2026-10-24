<?php
namespace UtsovAPI;
use PDO;
require(dirname(__FILE__).'/utils.php');
require(dirname(__FILE__).'/checkout-config.php'); // PAYPAL_API_BASE/CLIENT_ID/SECRET
require(dirname(__FILE__).'/checkin-lib.php');      // applyDayUpgrade()
date_default_timezone_set('America/New_York');

header('Content-Type: application/json');

// Gate-side payments: an admin (at the check-in desk) creates a payment "link" for a specific
// amount tied to a specific purpose (a Saturday->Both-Days upgrade, or a donation), and either
// opens it directly or shows its QR code for the payer's own phone. The link itself carries
// only an opaque token -- the amount and what it's for are looked up server-side from
// tb_gate_payments, so nobody can change what they're charged by editing the URL. Actual
// PayPal money only moves in 'createOrder'/'captureOrder' below, via the same Orders v2 API
// pattern already proven live in checkout.php and donate.php.

$_post = json_decode(file_get_contents("php://input"));
if (!$_post) { echo json_encode(['err' => 'Invalid request']); exit; }

function openGatePayDB() {
    $db = new PDO("sqlite:" . getDBPath("register"));
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA busy_timeout=5000");
    $db->exec("CREATE TABLE IF NOT EXISTS tb_gate_payments (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        token             TEXT UNIQUE,
        type              TEXT,
        registration_id   INTEGER,
        name              TEXT,
        email             TEXT,
        amount            REAL,
        upgrade_adults    INTEGER,
        upgrade_kids      INTEGER,
        payment_method    TEXT,
        station           TEXT,
        status            TEXT,
        paypal_order_id   TEXT,
        paypal_capture_id TEXT,
        created_at        TEXT,
        completed_at      TEXT
    )");
    return $db;
}

function gpGetToken() {
    $ch = curl_init(PAYPAL_API_BASE . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_USERPWD        => PAYPAL_CLIENT_ID . ':' . PAYPAL_SECRET,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Accept-Language: en_US'],
        CURLOPT_TIMEOUT        => 20, CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $err  = curl_errno($ch);
    curl_close($ch);
    if ($err) return null;
    $data = json_decode($body);
    return isset($data->access_token) ? $data->access_token : null;
}

function gpCreateOrder($token, $amount, $description) {
    $amountStr = number_format($amount, 2, '.', '');
    $payload = json_encode([
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'description' => $description,
            'amount' => ['currency_code' => 'USD', 'value' => $amountStr],
        ]],
    ]);
    $ch = curl_init(PAYPAL_API_BASE . '/v2/checkout/orders');
    curl_setopt_array($ch, [
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 20, CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $err  = curl_errno($ch);
    curl_close($ch);
    if ($err) return null;
    return json_decode($body);
}

function gpCaptureOrder($token, $orderId) {
    $ch = curl_init(PAYPAL_API_BASE . '/v2/checkout/orders/' . $orderId . '/capture');
    curl_setopt_array($ch, [
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS     => '{}',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 20, CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $err  = curl_errno($ch);
    curl_close($ch);
    if ($err) return null;
    return json_decode($body);
}

function gatePayUrl($token) {
    return 'https://utsov.org/gate-pay.html?t=' . urlencode($token);
}

$action = isset($_post->action) ? (string)$_post->action : '';
$db = openGatePayDB();

switch ($action) {

    // Admin-side (checkin.html): create a pending payment link for either an upgrade fee or
    // a donation. Returns the token + shareable URL; the amount is computed/validated HERE,
    // server-side, and locked into the row -- the payer's page can never alter it.
    case 'createLink': {
        $station = mb_substr(isset($_post->station) ? (string)$_post->station : 'Front Gate', 0, 100);
        $type = isset($_post->type) ? (string)$_post->type : '';

        if ($type === 'upgrade') {
            $regId = (int)(isset($_post->registrationId) ? $_post->registrationId : 0);
            $sel = $db->prepare("SELECT * FROM tb_registrations WHERE id = :id");
            $sel->execute([':id' => $regId]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['err' => 'Registration not found.']); break; }
            if ($row['status'] !== 'paid') { echo json_encode(['err' => 'Not marked as paid.']); break; }
            if ($row['days_selection'] !== 'saturday') { echo json_encode(['err' => 'Only a Saturday-only registration can be upgraded.']); break; }

            $curAdults = (int)$row['adults']; $curKids = (int)$row['kids_6to12'];
            $upAdults = max(0, min($curAdults, (int)(isset($_post->upgradeAdults) ? $_post->upgradeAdults : 0)));
            $upKids   = max(0, min($curKids,   (int)(isset($_post->upgradeKids)   ? $_post->upgradeKids   : 0)));
            if ($upAdults + $upKids <= 0) { echo json_encode(['err' => 'Select at least one person to upgrade.']); break; }

            $amount = round($upAdults * 55 + $upKids * 35, 2);
            if (isset($_post->customFee) && is_numeric($_post->customFee) && (float)$_post->customFee > 0) {
                $amount = round((float)$_post->customFee, 2);
            }
            $name = $row['name'];
            $email = $row['email'];
        } elseif ($type === 'donation') {
            $name = mb_substr(isset($_post->name) ? (string)$_post->name : '', 0, 200);
            $email = mb_substr(isset($_post->email) ? (string)$_post->email : '', 0, 254);
            $amount = filter_var(isset($_post->amount) ? $_post->amount : 0, FILTER_VALIDATE_FLOAT);
            if (!$name) { echo json_encode(['err' => 'Name is required.']); break; }
            if ($amount === false || $amount <= 0 || $amount > 50000) { echo json_encode(['err' => 'Enter a valid donation amount.']); break; }
            $amount = round($amount, 2);
            $regId = null; $upAdults = null; $upKids = null;
        } else {
            echo json_encode(['err' => 'Unknown payment type.']); break;
        }

        $token = bin2hex(openssl_random_pseudo_bytes(16));
        $now = date('Y-m-d H:i:s');
        $ins = $db->prepare(
            "INSERT INTO tb_gate_payments
                (token, type, registration_id, name, email, amount, upgrade_adults, upgrade_kids,
                 payment_method, station, status, created_at)
             VALUES
                (:token, :type, :regid, :name, :email, :amount, :upa, :upk,
                 'paypal', :station, 'pending', :now)"
        );
        $ins->execute([
            ':token' => $token, ':type' => $type, ':regid' => $regId, ':name' => $name, ':email' => $email,
            ':amount' => $amount, ':upa' => $upAdults, ':upk' => $upKids, ':station' => $station, ':now' => $now,
        ]);

        echo json_encode(['ok' => true, 'token' => $token, 'url' => gatePayUrl($token), 'amount' => $amount]);
        break;
    }

    // Public (gate-pay.html on load): resolve a token to what's being paid for + how much,
    // without exposing anything the payer could use to tamper with the amount.
    case 'resolve': {
        $token = isset($_post->token) ? (string)$_post->token : '';
        $sel = $db->prepare("SELECT * FROM tb_gate_payments WHERE token = :t");
        $sel->execute([':t' => $token]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['err' => 'Payment link not found.']); break; }
        if ($row['status'] === 'completed') { echo json_encode(['err' => 'This payment has already been completed.']); break; }

        $desc = $row['type'] === 'upgrade'
            ? ($row['name'] . "'s Saturday → Both Days upgrade (" . $row['upgrade_adults'] . ' adult(s), ' . $row['upgrade_kids'] . ' kid(s))')
            : ('Donation' . ($row['name'] ? ' from ' . $row['name'] : ''));

        echo json_encode(['ok' => true, 'type' => $row['type'], 'amount' => (float)$row['amount'], 'description' => $desc]);
        break;
    }

    // Public (gate-pay.html's PayPal button createOrder): creates a REAL PayPal order for the
    // token's locked-in amount. No money moves yet -- that only happens on captureOrder below.
    case 'createOrder': {
        $token = isset($_post->token) ? (string)$_post->token : '';
        $sel = $db->prepare("SELECT * FROM tb_gate_payments WHERE token = :t");
        $sel->execute([':t' => $token]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['err' => 'Payment link not found.']); break; }
        if ($row['status'] !== 'pending') { echo json_encode(['err' => 'This payment is no longer available.']); break; }

        $ppToken = gpGetToken();
        if (!$ppToken) { echo json_encode(['err' => 'Payment service unavailable. Please try again.']); break; }

        $desc = $row['type'] === 'upgrade' ? 'UTSOV 2026 Both Days Upgrade' : 'UTSOV 2026 Donation';
        $order = gpCreateOrder($ppToken, (float)$row['amount'], $desc);
        if (!$order || !isset($order->id)) { echo json_encode(['err' => 'Could not start payment. Please try again.']); break; }

        $upd = $db->prepare("UPDATE tb_gate_payments SET paypal_order_id = :oid WHERE token = :t");
        $upd->execute([':oid' => $order->id, ':t' => $token]);

        echo json_encode(['ok' => true, 'paypalOrderId' => $order->id]);
        break;
    }

    // Public (gate-pay.html's PayPal button onApprove): captures the real payment, verifies
    // the captured amount matches what was locked in (defense-in-depth, same check
    // checkout.php already does), and ONLY THEN applies the upgrade/records the donation.
    case 'captureOrder': {
        $token = isset($_post->token) ? (string)$_post->token : '';
        $orderId = isset($_post->paypalOrderId) ? (string)$_post->paypalOrderId : '';

        $sel = $db->prepare("SELECT * FROM tb_gate_payments WHERE token = :t");
        $sel->execute([':t' => $token]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['err' => 'Payment link not found.']); break; }
        if ($row['status'] === 'completed') { echo json_encode(['ok' => true, 'alreadyDone' => true]); break; }
        if ($row['paypal_order_id'] !== $orderId) { echo json_encode(['err' => 'Order mismatch. Please refresh and try again.']); break; }

        $ppToken = gpGetToken();
        if (!$ppToken) { echo json_encode(['err' => 'Payment service unavailable.']); break; }

        $capture = gpCaptureOrder($ppToken, $orderId);
        if (!$capture || !isset($capture->status)) { echo json_encode(['err' => 'Capture failed. Please contact the front desk.']); break; }
        if ($capture->status !== 'COMPLETED') { echo json_encode(['err' => 'Payment not completed. Status: ' . htmlspecialchars($capture->status, ENT_QUOTES, 'UTF-8')]); break; }

        $capturedAmount = isset($capture->purchase_units[0]->payments->captures[0]->amount->value)
            ? (float)$capture->purchase_units[0]->payments->captures[0]->amount->value : 0;
        if (abs($capturedAmount - (float)$row['amount']) > 0.01) {
            echo json_encode(['err' => 'Payment amount mismatch. Please contact the front desk.']); break;
        }
        $captureId = $capture->purchase_units[0]->payments->captures[0]->id;
        $now = date('Y-m-d H:i:s');

        if ($row['type'] === 'upgrade') {
            $sel2 = $db->prepare("SELECT * FROM tb_registrations WHERE id = :id");
            $sel2->execute([':id' => $row['registration_id']]);
            $regRow = $sel2->fetch(PDO::FETCH_ASSOC);
            if (!$regRow || $regRow['status'] !== 'paid' || $regRow['days_selection'] !== 'saturday') {
                // Money is already captured at this point -- do NOT lose that link. Flag it
                // instead of silently failing, so a human reconciles it rather than the
                // payer being charged with nothing recorded against their registration.
                $upd = $db->prepare("UPDATE tb_gate_payments SET status='needs_review', paypal_capture_id=:cid, completed_at=:now WHERE token=:t");
                $upd->execute([':cid' => $captureId, ':now' => $now, ':t' => $token]);
                echo json_encode(['err' => 'Payment captured, but the registration changed since this link was created. Please contact the front desk -- your payment is safe and recorded.']);
                break;
            }
            try {
                $db->beginTransaction();
                applyDayUpgrade($db, $regRow, (int)$row['upgrade_adults'], (int)$row['upgrade_kids'], (float)$row['amount'], 'paypal', $row['station']);
                $upd = $db->prepare("UPDATE tb_gate_payments SET status='completed', paypal_capture_id=:cid, completed_at=:now WHERE token=:t");
                $upd->execute([':cid' => $captureId, ':now' => $now, ':t' => $token]);
                $db->commit();
            } catch (\PDOException $e) {
                $db->rollBack();
                $upd = $db->prepare("UPDATE tb_gate_payments SET status='needs_review', paypal_capture_id=:cid, completed_at=:now WHERE token=:t");
                $upd->execute([':cid' => $captureId, ':now' => $now, ':t' => $token]);
                echo json_encode(['err' => 'Payment captured, but applying the upgrade failed. Please contact the front desk -- your payment is safe and recorded.']);
                break;
            }
        } else {
            $upd = $db->prepare("UPDATE tb_gate_payments SET status='completed', paypal_capture_id=:cid, completed_at=:now WHERE token=:t");
            $upd->execute([':cid' => $captureId, ':now' => $now, ':t' => $token]);
        }

        echo json_encode(['ok' => true]);
        break;
    }

    // Admin-side: record a donation paid by cash/zelle/check/card (no PayPal round-trip --
    // completed immediately, since the desk already has the money in hand).
    case 'manualDonation': {
        $station = mb_substr(isset($_post->station) ? (string)$_post->station : 'Front Gate', 0, 100);
        $name = mb_substr(isset($_post->name) ? (string)$_post->name : '', 0, 200);
        $email = mb_substr(isset($_post->email) ? (string)$_post->email : '', 0, 254);
        $amount = filter_var(isset($_post->amount) ? $_post->amount : 0, FILTER_VALIDATE_FLOAT);
        $allowedPay = ['cash', 'zelle', 'check', 'card'];
        $paymentMethod = (isset($_post->paymentMethod) && in_array($_post->paymentMethod, $allowedPay, true)) ? $_post->paymentMethod : 'cash';

        if (!$name) { echo json_encode(['err' => 'Name is required.']); break; }
        if ($amount === false || $amount <= 0 || $amount > 50000) { echo json_encode(['err' => 'Enter a valid donation amount.']); break; }
        $amount = round($amount, 2);

        $token = bin2hex(openssl_random_pseudo_bytes(16));
        $now = date('Y-m-d H:i:s');
        $ins = $db->prepare(
            "INSERT INTO tb_gate_payments
                (token, type, name, email, amount, payment_method, station, status, created_at, completed_at)
             VALUES
                (:token, 'donation', :name, :email, :amount, :pm, :station, 'completed', :now, :now)"
        );
        $ins->execute([
            ':token' => $token, ':name' => $name, ':email' => $email, ':amount' => $amount,
            ':pm' => $paymentMethod, ':station' => $station, ':now' => $now,
        ]);

        echo json_encode(['ok' => true]);
        break;
    }

    default:
        echo json_encode(['err' => 'Unknown action']);
}
