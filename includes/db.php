<?php
/**
 * Database bootstrap. Returns a shared PDO connection to MySQL.
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = require __DIR__ . '/../config/config.php';
    $d   = $cfg['db'];

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $d['host'],
        $d['port'],
        $d['name']
    );

    try {
        $pdo = new PDO($dsn, $d['user'], $d['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        die(
            "<h2>Database connection failed</h2>" .
            "<p>Check <code>config/config.php</code> (or your DB_* environment variables) " .
            "and make sure MySQL is running and the schema has been loaded.</p>" .
            "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>"
        );
    }

    return $pdo;
}

/** Convenience: app config array. */
function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/../config/config.php';
    }
    return $cfg;
}
