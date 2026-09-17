<?php
namespace UtsovAPI;

/**
 * Builds the string encoded into a registrant's check-in QR code: their registration id,
 * signed with CHECKIN_SIGNING_SECRET so a forged QR (someone just guessing/incrementing an
 * order id) can never pass verification without knowing the secret.
 */
function generateCheckinToken($id) {
    $id = (int)$id;
    $sig = substr(hash_hmac('sha256', (string)$id, CHECKIN_SIGNING_SECRET), 0, 12);
    return "UTSOV-CHECKIN:{$id}.{$sig}";
}

/**
 * Verifies a scanned QR token. Returns the registration id (int) if the signature is valid,
 * or null if the token is malformed or forged/tampered.
 */
function verifyCheckinToken($token) {
    $token = trim((string)$token);
    $prefix = 'UTSOV-CHECKIN:';
    if (strpos($token, $prefix) !== 0) return null;
    $rest = substr($token, strlen($prefix));

    $dot = strrpos($rest, '.');
    if ($dot === false) return null;

    $idPart  = substr($rest, 0, $dot);
    $sigPart = substr($rest, $dot + 1);
    if (!ctype_digit($idPart) || $idPart === '') return null;

    $expected = substr(hash_hmac('sha256', $idPart, CHECKIN_SIGNING_SECRET), 0, 12);
    if (!hash_equals($expected, $sigPart)) return null;

    return (int)$idPart;
}
