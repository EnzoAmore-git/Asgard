<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$database = getenv('ASGARD_DB_DATABASE') ?: '';
if (!in_array($database, ['asgard_environment', 'asgard_ci'], true)) {
    fwrite(STDERR, "Use a dedicated asgard_environment or asgard_ci database.\n");
    exit(1);
}
$port = filter_var(getenv('ASGARD_DB_PORT') ?: '3306', FILTER_VALIDATE_INT);
if ($port === false || $port < 1 || $port > 65535) {
    fwrite(STDERR, "Invalid MySQL port.\n");
    exit(1);
}
$host = getenv('ASGARD_DB_HOST') ?: '127.0.0.1';
if (str_contains($host, ';')) {
    fwrite(STDERR, "Invalid MySQL host.\n");
    exit(1);
}
$pdo = null;
$suffix = bin2hex(random_bytes(6));
$parent = 'asgard_env_parent_' . $suffix;
$child = 'asgard_env_child_' . $suffix;
$checks = [];
$exitCode = 0;
try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4",
        getenv('ASGARD_DB_USERNAME') ?: '', getenv('ASGARD_DB_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    if (!str_starts_with($version, '8.4.') || stripos($version, 'mariadb') !== false) {
        throw new RuntimeException('Expected MySQL 8.4.');
    }
    $checks[] = 'MySQL 8.4';
    $pdo->exec("CREATE TABLE `$parent` (id BIGINT PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE `$child` (id BIGINT PRIMARY KEY, parent_id BIGINT NOT NULL, FOREIGN KEY (parent_id) REFERENCES `$parent` (id)) ENGINE=InnoDB");
    $checks[] = 'InnoDB and foreign keys';
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO `$parent` VALUES (?, ?)")->execute([1, 'Проверка Асгарда']);
    $pdo->rollBack();
    if ((int) $pdo->query("SELECT COUNT(*) FROM `$parent`")->fetchColumn() !== 0) {
        throw new RuntimeException('Rollback failed.');
    }
    $checks[] = 'Transaction rollback';
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO `$parent` VALUES (?, ?)")->execute([1, 'Проверка Асгарда']);
    $pdo->commit();
    if ($pdo->query("SELECT name FROM `$parent` WHERE id=1")->fetchColumn() !== 'Проверка Асгарда') {
        throw new RuntimeException('UTF-8 round-trip failed.');
    }
    $checks[] = 'Commit and Cyrillic round-trip';
    try {
        $pdo->prepare("INSERT INTO `$parent` VALUES (?, ?)")->execute([2, 'Проверка Асгарда']);
        throw new RuntimeException('Unique constraint did not reject a duplicate.');
    } catch (PDOException $error) {
        if (($error->errorInfo[1] ?? null) !== 1062) { throw $error; }
    }
    $checks[] = 'Unique constraint';
    try {
        $pdo->exec("INSERT INTO `$child` VALUES (1,999)");
        throw new RuntimeException('Foreign key did not reject an invalid reference.');
    } catch (PDOException $error) {
        if (($error->errorInfo[1] ?? null) !== 1452) { throw $error; }
    }
    $checks[] = 'Invalid foreign key rejected';
    echo json_encode(['ok' => true, 'mysql' => $version, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    // Do not expose credentials, connection details or provider responses.
    fwrite(STDERR, 'Environment database check failed: ' . get_class($error) . PHP_EOL);
    $exitCode = 1;
} finally {
    if ($pdo instanceof PDO) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $pdo->exec("DROP TABLE IF EXISTS `$child`");
        $pdo->exec("DROP TABLE IF EXISTS `$parent`");
    }
}
exit($exitCode);
