<?php
namespace UtsovAPI;
header('Content-Type: application/json');
echo json_encode(['ok'=>true,'php'=>phpversion(),'curl'=>function_exists('curl_version')]);
