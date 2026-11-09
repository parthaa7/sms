<?php
declare(strict_types=1);

define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'sms_db');
define('DB_USER', 'root');
define('DB_PASS', '');

define('APP_DEBUG', true);
define('APP_TIMEZONE', 'Asia/Kolkata');
define('GST_RATE', 0.05);
define('SESSION_IDLE_TIMEOUT', 1800);
define('STORE_NAME', 'SMS SuperMart');
define('STORE_ADDRESS', '123 Market Road, Your City');
define('STORE_GSTIN', '00XXXXX0000X0Z0');

date_default_timezone_set(APP_TIMEZONE);

final class Database
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
            try {
                self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                ]);
                self::$pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
            } catch (PDOException $e) {
                error_log('[SMS] DB connection failed: ' . $e->getMessage());
                http_response_code(503);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => 'Database is unavailable. Please try again later.']);
                exit;
            }
        }
        return self::$pdo;
    }

    public static function rollbackIfActive(): void
    {
        if (self::$pdo !== null && self::$pdo->inTransaction()) {
            self::$pdo->rollBack();
        }
    }
}

function db(): PDO
{
    return Database::get();
}
