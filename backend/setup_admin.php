<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/db.php';

[, $username, $password, $role] = array_pad($argv, 4, null);
$role ??= 'Administrator';

if (!$username || !$password) {
    fwrite(STDERR, "Usage: php setup_admin.php <username> <password> [Administrator|Cashier]\n");
    exit(1);
}
if (!preg_match('/^[A-Za-z0-9_.]{3,50}$/', $username) || strlen($password) < 8 || !in_array($role, ['Administrator', 'Cashier'], true)) {
    fwrite(STDERR, "Invalid input: username 3-50 chars [A-Za-z0-9_.], password >= 8 chars, role Administrator|Cashier.\n");
    exit(1);
}

db()->prepare('INSERT INTO users (username, full_name, password_hash, role_enum, is_active)
               VALUES (?, ?, ?, ?, 1)
               ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role_enum = VALUES(role_enum),
                                       is_active = 1, failed_attempts = 0, locked_until = NULL')
    ->execute([$username, ucfirst($username), password_hash($password, PASSWORD_DEFAULT), $role]);

echo "User '{$username}' ({$role}) is ready.\n";
