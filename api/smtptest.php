<?php
require_once dirname(__FILE__) . '/mailer.php';
header('Content-Type: text/plain');
echo "Testing Brevo HTTP API...\n";
$result = utsovSendMail('skdas8075@gmail.com', 'UTSOV Email Test', '<h2 style="color:#8B1A1A">UTSOV 2026 Email Test</h2><p>Brevo HTTP API is working! Emails will now land in inbox.</p>');
echo $result ? "SUCCESS - check skdas8075@gmail.com inbox!\n" : "FAILED - check api/error_log\n";
