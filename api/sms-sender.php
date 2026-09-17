<?php
namespace UtsovAPI;

/**
 * Normalizes a loosely-formatted phone number (as stored in tb_registrations — seen as
 * "6094955111", "+1 (609) 495-5111", "19082026775", "908 302 6775" etc.) to E.164 for
 * Twilio. Returns null if it can't confidently be normalized as a US/Canada number,
 * rather than guessing and sending to a wrong/invalid number.
 */
function normalizePhoneE164($raw) {
    $digits = preg_replace('/\D+/', '', (string)$raw);
    if (strlen($digits) === 10) {
        $digits = '1' . $digits;
    }
    if (strlen($digits) !== 11 || $digits[0] !== '1') {
        return null;
    }
    return '+' . $digits;
}

/**
 * Sends an SMS via the UTSOV Twilio Messaging Service. Best-effort: never throws,
 * always returns bool. Callers should treat this as a bonus notification — a failure
 * here must never break the email confirmation / checkout / resend flow it's attached to.
 */
function sendTwilioSMS($toPhoneRaw, $body) {
    if (!defined('TWILIO_ACCOUNT_SID') || !defined('TWILIO_AUTH_TOKEN') || !defined('TWILIO_MESSAGING_SERVICE_SID')) {
        return false;
    }
    $to = normalizePhoneE164($toPhoneRaw);
    if (!$to) return false;

    try {
        $ch = curl_init('https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_ACCOUNT_SID . '/Messages.json');
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(array(
                'MessagingServiceSid' => TWILIO_MESSAGING_SERVICE_SID,
                'To'                  => $to,
                'Body'                => $body,
            )),
            CURLOPT_USERPWD        => TWILIO_ACCOUNT_SID . ':' . TWILIO_AUTH_TOKEN,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
        ));
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_errno($ch);
        curl_close($ch);
        return (!$curlErr && $httpCode >= 200 && $httpCode < 300);
    } catch (\Exception $e) {
        // Server runs PHP 5.6 — no Throwable/Error interface exists there, only Exception.
        // curl_* calls don't throw in 5.6 anyway; this stays as defense-in-depth only.
        return false;
    }
}
