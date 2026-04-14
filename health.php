<?php
header('Content-Type: application/json');
echo json_encode([
    'status' => 'ok',
    'service' => 'solwed-erp',
    'uptime' => (int)(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']),
]);
