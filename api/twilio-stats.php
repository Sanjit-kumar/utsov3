<?php
namespace UtsovAPI;

// Twilio returns message timestamps in UTC - bucket "today"/"this month" in the same
// zone the rest of the app uses (checkout.php), so they match the organizers' local day.
date_default_timezone_set('America/New_York');

/**
 * Read-only Twilio account reporting for the admin dashboard (balance, usage/cost,
 * recent messages) — a lightweight mirror of the numbers on Twilio's own console.
 * Mirrors sms-sender.php's Basic Auth cURL pattern. Every call is best-effort:
 * returns null/empty on any failure (missing config, network error, bad response)
 * rather than throwing, since this is a reporting add-on and must never break the
 * rest of admin.php if Twilio is slow or unreachable.
 */

function twilioApiGet($path, $params = array()) {
    if (!defined('TWILIO_ACCOUNT_SID') || !defined('TWILIO_AUTH_TOKEN')) return null;
    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_ACCOUNT_SID . '/' . $path;
    if ($params) $url .= '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => TWILIO_ACCOUNT_SID . ':' . TWILIO_AUTH_TOKEN,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ));
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_errno($ch);
    curl_close($ch);
    if ($curlErr || $httpCode < 200 || $httpCode >= 300 || !$resp) return null;
    $data = json_decode($resp, true);
    return is_array($data) ? $data : null;
}

function twilioGetBalance() {
    $data = twilioApiGet('Balance.json');
    if (!$data || !isset($data['balance'])) return null;
    return array(
        'balance'  => (float)$data['balance'],
        'currency' => isset($data['currency']) ? $data['currency'] : 'USD',
    );
}

/**
 * Fetches the most recent messages (up to $limit, Twilio's default newest-first order)
 * once, and buckets them into today/this-month/all-time counts + known cost client-side.
 *
 * Deliberately NOT using Twilio's Usage Records API for these counts - confirmed live
 * (2026-09-17) that Usage/Records/*.json reports 0 count/$0 for hours after messages
 * are already showing status=delivered in the Messages list, i.e. usage aggregation
 * lags well behind actual sends. The Messages list is real-time, so bucket that instead.
 * Per-message `price` is often null for a while after delivery (Twilio prices
 * asynchronously) - those are counted separately as "pending" rather than silently
 * treated as $0, so the admin view doesn't understate cost as messages settle.
 *
 * Caveat: only reflects the most recent $limit messages, so "All-Time" undercounts if
 * the account has ever sent more than that - fine for this org's yearly SMS volume.
 */
function twilioGetMessageStats($limit = 1000) {
    $out = array(
        'ok' => false, 'messages' => array(),
        'today'     => array('count' => 0, 'cost' => 0.0, 'pending' => 0),
        'thisMonth' => array('count' => 0, 'cost' => 0.0, 'pending' => 0),
        'allTime'   => array('count' => 0, 'cost' => 0.0, 'pending' => 0),
    );
    $data = twilioApiGet('Messages.json', array('PageSize' => $limit));
    if (!$data || !isset($data['messages'])) return $out;
    $out['ok']       = true;
    $out['messages'] = $data['messages'];

    $todayStr = date('Y-m-d');
    $monthStr = date('Y-m');

    foreach ($data['messages'] as $m) {
        $sentAt = !empty($m['date_sent']) ? $m['date_sent'] : (!empty($m['date_created']) ? $m['date_created'] : null);
        $hasPrice = isset($m['price']) && $m['price'] !== null;
        $cost = $hasPrice ? abs((float)$m['price']) : 0.0;

        $out['allTime']['count']++;
        if ($hasPrice) $out['allTime']['cost'] += $cost; else $out['allTime']['pending']++;

        if (!$sentAt) continue;
        $ts = strtotime($sentAt);
        if ($ts === false) continue;

        if (date('Y-m', $ts) === $monthStr) {
            $out['thisMonth']['count']++;
            if ($hasPrice) $out['thisMonth']['cost'] += $cost; else $out['thisMonth']['pending']++;
        }
        if (date('Y-m-d', $ts) === $todayStr) {
            $out['today']['count']++;
            if ($hasPrice) $out['today']['cost'] += $cost; else $out['today']['pending']++;
        }
    }
    return $out;
}
?>
