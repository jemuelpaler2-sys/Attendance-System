<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Shared PDO connection (singleton).
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        $message = APP_DEBUG
            ? 'Database connection failed: ' . $e->getMessage()
            : 'Unable to connect to the database. Please contact the administrator.';
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Database error</title>'
            . '<style>body{font-family:Roboto,Arial,sans-serif;background:#f6f8fc;color:#202124;display:flex;'
            . 'align-items:center;justify-content:center;min-height:100vh;margin:0;padding:24px}'
            . '.box{background:#fff;border:1px solid #dadce0;border-radius:20px;padding:32px;max-width:620px;'
            . 'box-shadow:0 8px 30px rgba(60,64,67,.15)}h1{font-size:20px;margin:0 0 12px}'
            . 'p{color:#5f6368;line-height:1.6;margin:0 0 16px}code{background:#f1f3f4;padding:2px 6px;'
            . 'border-radius:6px;font-size:13px}</style></head><body><div class="box">'
            . '<h1>Database connection error</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>Make sure MySQL is running in XAMPP and that <code>database.sql</code> has been imported.</p>'
            . '</div></body></html>';
        exit;
    }

    return $pdo;
}
