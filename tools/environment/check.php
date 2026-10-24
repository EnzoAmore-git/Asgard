<?php

declare(strict_types=1);

// CLI only: this diagnostic must never become a public web endpoint.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$required = ['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'hash', 'mbstring',
    'openssl', 'pcre', 'PDO', 'pdo_mysql', 'session', 'tokenizer', 'xml', 'intl', 'zip', 'sodium'];
$missing = array_values(array_filter($required, fn (string $name): bool => !extension_loaded($name)));
$versionOk = PHP_VERSION_ID >= 80400 && PHP_VERSION_ID < 80500;
echo json_encode([
    'ok' => $versionOk && $missing === [],
    'php' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'missing_extensions' => $missing,
    'memory_limit' => ini_get('memory_limit'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
exit($versionOk && $missing === [] ? 0 : 1);
