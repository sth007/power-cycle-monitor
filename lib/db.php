<?php
declare(strict_types=1);

function getConfig(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $configFile = __DIR__ . '/../config/app.php';
    if (!is_file($configFile)) {
        throw new RuntimeException('Konfigurationsdatei fehlt: config/app.php (app.sample.php kopieren)');
    }

    $config = require $configFile;
    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = getConfig()['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'],
        (int)$cfg['port'],
        $cfg['dbname'],
        $cfg['charset']
    );

    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
	PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);

    return $pdo;
}
