<?php
// Global-namespace email helper using Brevo HTTP API (HTTPS port 443 — works on all shared hosts)
// Call as utsovSendMail() from UtsovAPI-namespaced files (PHP falls back to global namespace)

if (!defined('BREVO_API_KEY')) {
    require_once dirname(__FILE__) . '/mail-config.php';
}

function utsovSendMail($to, $subject, $htmlBody) {
    $payload = json_encode(array(
        'sender'      => array('name' => 'UTSOV 2026', 'email' => 'utsov@utsov.org'),
        'to'          => array(array('email' => $to)),
        'subject'     => $subject,
        'htmlContent' => $htmlBody
    ));

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => array(
            'accept: application/json',
            'api-key: ' . BREVO_API_KEY,
            'content-type: application/json'
        ),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ));

    $response = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr   = curl_error($ch);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    }
    error_log('UTSOV Brevo error: HTTP=' . $httpCode . ' resp=' . $response . ' curl=' . $curlErr);
    return false;
}
