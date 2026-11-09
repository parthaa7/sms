<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_error_handler(static function (int $sev, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $sev)) return false;
    throw new ErrorException($msg, 0, $sev, $file, $line);
});

class ApiException extends RuntimeException
{
    public function __construct(string $message, int $status = 400, public readonly array $details = [])
    {
        parent::__construct($message, $status);
    }
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function translate_pdo_exception(PDOException $e): ?ApiException
{
    return match ($e->errorInfo[1] ?? null) {
        1062       => new ApiException('A record with the same unique value (e.g. name, barcode, username) already exists.', 409),
        1451       => new ApiException('This record is in use by other data and cannot be deleted. Deactivate it instead.', 409),
        1452       => new ApiException('A referenced record (category, supplier, customer...) does not exist.', 422),
        3819, 4025 => new ApiException('A value violates a database constraint (e.g. negative price).', 422),
        default    => null,
    };
}

function run_api(callable $handler): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    try {
        json_response(['success' => true, 'data' => $handler()]);
    } catch (ApiException $e) {
        Database::rollbackIfActive();
        json_response(['success' => false, 'error' => $e->getMessage(), 'details' => $e->details], $e->getCode() ?: 400);
    } catch (PDOException $e) {
        Database::rollbackIfActive();
        if ($mapped = translate_pdo_exception($e)) {
            json_response(['success' => false, 'error' => $mapped->getMessage()], $mapped->getCode());
        }
        error_log('[SMS] SQL error: ' . $e->getMessage());
        json_response(['success' => false, 'error' => APP_DEBUG ? $e->getMessage() : 'A database error occurred.'], 500);
    } catch (Throwable $e) {
        Database::rollbackIfActive();
        error_log('[SMS] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        json_response(['success' => false, 'error' => APP_DEBUG ? $e->getMessage() : 'Internal server error.'], 500);
    }
}

function is_direct_request(string $file): bool
{
    return realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath($file);
}

function require_method(string ...$methods): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        throw new ApiException('Method not allowed.', 405);
    }
}

function get_input(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) return $cache = [];
        $data = json_decode($raw, true);
        if (!is_array($data)) throw new ApiException('Malformed JSON body.', 400);
        return $cache = $data;
    }
    return $cache = $_POST;
}

function clean_string(string $s): string
{
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', trim($s)) ?? '';
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
}

function validate(array $input, array $rules, bool $partial = false): array
{
    $clean = [];
    $errors = [];

    foreach ($rules as $field => $ruleString) {
        if ($partial && !array_key_exists($field, $input)) continue;

        $ruleList = explode('|', $ruleString);
        $value = $input[$field] ?? null;
        if (is_string($value)) $value = clean_string($value);

        if ($value === null || $value === '') {
            if (in_array('required', $ruleList, true)) {
                $errors[$field] = 'This field is required.';
            } elseif (array_key_exists($field, $input) && in_array('nullable', $ruleList, true)) {
                $clean[$field] = null;
            }
            continue;
        }

        foreach ($ruleList as $rule) {
            [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
            if (($err = apply_rule($value, $name, $param)) !== null) {
                $errors[$field] = $err;
                break;
            }
        }
        if (!isset($errors[$field])) $clean[$field] = $value;
    }

    if ($errors) throw new ApiException('Validation failed.', 422, $errors);
    return $clean;
}

function apply_rule(mixed &$value, string $name, ?string $param): ?string
{
    switch ($name) {
        case 'required':
        case 'nullable':
            return null;
        case 'string':
            if (!is_scalar($value)) return 'Must be text.';
            $value = (string) $value;
            return null;
        case 'int':
            $v = filter_var($value, FILTER_VALIDATE_INT);
            if ($v === false) return 'Must be a whole number.';
            $value = $v;
            return null;
        case 'decimal':
            if (!is_numeric($value)) return 'Must be a number.';
            $value = round((float) $value, 2);
            return null;
        case 'bool':
            $v = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($v === null) return 'Must be true or false.';
            $value = (int) $v;
            return null;
        case 'min':
            if (is_int($value) || is_float($value)) return $value < (float) $param ? "Must be at least {$param}." : null;
            return mb_strlen((string) $value) < (int) $param ? "Must be at least {$param} characters." : null;
        case 'max':
            if (is_int($value) || is_float($value)) return $value > (float) $param ? "Must not exceed {$param}." : null;
            return mb_strlen((string) $value) > (int) $param ? "Must not exceed {$param} characters." : null;
        case 'email':
            return filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Invalid email address.';
        case 'phone':
            $value = (string) $value;
            return preg_match('/^\+?[0-9]{7,15}$/', $value) ? null : 'Phone must be 7-15 digits (optional leading +).';
        case 'username':
            return preg_match('/^[A-Za-z0-9_.]{3,50}$/', (string) $value) ? null : 'Use 3-50 letters, digits, dot or underscore.';
        case 'barcode':
            $value = (string) $value;
            return preg_match('/^[A-Za-z0-9\-]{4,64}$/', $value) ? null : 'Barcode must be 4-64 letters/digits/hyphens.';
        case 'in':
            $opts = explode(',', (string) $param);
            return in_array((string) $value, $opts, true) ? null : 'Must be one of: ' . implode(', ', $opts) . '.';
        default:
            throw new LogicException("Unknown validation rule '{$name}'.");
    }
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('SMSSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function csrf_token(): string
{
    start_secure_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? (get_input()['csrf_token'] ?? '');
    if (!is_string($sent) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sent)) {
        throw new ApiException('Invalid or missing CSRF token. Please refresh and log in again.', 403);
    }
}

function transaction(callable $fn): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function output_csv(string $filename, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
    $out = fopen('php://output', 'w');
    if ($rows) fputcsv($out, array_keys($rows[0]));
    foreach ($rows as $row) {
        fputcsv($out, array_map(static fn($v) =>
            (is_string($v) && !is_numeric($v) && preg_match('/^[=+\-@\t\r]/', $v)) ? "'" . $v : $v, $row));
    }
    fclose($out);
    exit;
}
