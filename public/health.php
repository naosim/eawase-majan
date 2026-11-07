<?php
declare(strict_types=1);

$checks = [
    'php' => version_compare(PHP_VERSION, '8.3.0', '>='),
    'pdo_sqlite' => extension_loaded('pdo_sqlite'),
    'json_validate' => function_exists('json_validate'),
];
$ready = !in_array(false, $checks, true);

http_response_code($ready ? 200 : 503);
header('Content-Type: application/json; charset=UTF-8');
echo json_encode([
    'status' => $ready ? 'ok' : 'error',
    'php_version' => PHP_VERSION,
    'checks' => $checks,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
