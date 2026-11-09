<?php
declare(strict_types=1);
require_once __DIR__ . '/utils.php';

final class Auth
{
    public const ROLE_ADMIN   = 'Administrator';
    public const ROLE_CASHIER = 'Cashier';
    private const MAX_ATTEMPTS    = 5;
    private const LOCKOUT_SECONDS = 300;

    public static function login(string $username, string $password): array
    {
        start_secure_session();
        $pdo = db();

        $stmt = $pdo->prepare('SELECT user_id, username, full_name, password_hash, role_enum, is_active,
                                      failed_attempts, locked_until
                               FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && $user['locked_until'] && strtotime($user['locked_until']) > time()) {
            throw new ApiException('Too many failed attempts. Account locked for a few minutes.', 429);
        }

        $valid = $user && (int) $user['is_active'] === 1 && password_verify($password, $user['password_hash']);

        if (!$valid) {
            if ($user) {
                $lockUntil = ((int) $user['failed_attempts'] + 1 >= self::MAX_ATTEMPTS)
                    ? date('Y-m-d H:i:s', time() + self::LOCKOUT_SECONDS) : null;
                $pdo->prepare('UPDATE users SET failed_attempts = failed_attempts + 1, locked_until = ? WHERE user_id = ?')
                    ->execute([$lockUntil, $user['user_id']]);
            }
            throw new ApiException('Invalid username or password.', 401);
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $user['user_id']]);
        }
        $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login = NOW() WHERE user_id = ?')
            ->execute([$user['user_id']]);

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['user_id'];
        $_SESSION['last_activity'] = time();
        unset($_SESSION['csrf_token']);

        return [
            'user'       => self::publicUser($user),
            'csrf_token' => csrf_token(),
        ];
    }

    public static function requireLogin(): array
    {
        start_secure_session();
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) throw new ApiException('Authentication required.', 401);

        if (time() - (int) ($_SESSION['last_activity'] ?? 0) > SESSION_IDLE_TIMEOUT) {
            self::destroySession();
            throw new ApiException('Session expired. Please log in again.', 401);
        }

        $stmt = db()->prepare('SELECT user_id, username, full_name, role_enum, is_active FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user || (int) $user['is_active'] !== 1) {
            self::destroySession();
            throw new ApiException('Your account is disabled.', 401);
        }

        $_SESSION['last_activity'] = time();
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            verify_csrf();
        }
        session_write_close();
        return self::publicUser($user);
    }

    public static function requireRole(string ...$roles): array
    {
        $user = self::requireLogin();
        if (!in_array($user['role'], $roles, true)) {
            throw new ApiException('You do not have permission to perform this action.', 403);
        }
        return $user;
    }

    public static function changePassword(int $userId, string $current, string $new): void
    {
        $stmt = db()->prepare('SELECT password_hash FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();
        if (!$hash || !password_verify($current, $hash)) {
            throw new ApiException('Current password is incorrect.', 422);
        }
        db()->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
    }

    public static function destroySession(): void
    {
        start_secure_session();
        $_SESSION = [];
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000, 'path' => $p['path'], 'secure' => $p['secure'],
            'httponly' => $p['httponly'], 'samesite' => $p['samesite'],
        ]);
        session_destroy();
    }

    private static function publicUser(array $u): array
    {
        return [
            'user_id'   => (int) $u['user_id'],
            'username'  => $u['username'],
            'full_name' => $u['full_name'],
            'role'      => $u['role_enum'],
        ];
    }
}

if (is_direct_request(__FILE__)) {
    run_api(static function () {
        switch ($_GET['action'] ?? '') {
            case 'login':
                require_method('POST');
                $in = validate(get_input(), ['username' => 'required|string|max:50', 'password' => 'required|string|max:72']);
                return Auth::login($in['username'], $in['password']);

            case 'logout':
                require_method('POST');
                Auth::requireLogin();
                Auth::destroySession();
                return ['message' => 'Logged out.'];

            case 'me':
                require_method('GET');
                $user = Auth::requireLogin();
                start_secure_session();
                return ['user' => $user, 'csrf_token' => csrf_token()];

            case 'change_password':
                require_method('POST');
                $user = Auth::requireLogin();
                $in = validate(get_input(), [
                    'current_password' => 'required|string|max:72',
                    'new_password'     => 'required|string|min:8|max:72',
                ]);
                Auth::changePassword($user['user_id'], $in['current_password'], $in['new_password']);
                return ['message' => 'Password updated.'];

            default:
                throw new ApiException('Unknown action.', 404);
        }
    });
}
