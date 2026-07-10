<?php
header('Content-Type: application/json');
echo json_encode(array('ok' => true, 'php' => PHP_VERSION, 'ts' => date('Y-m-d H:i:s')));
