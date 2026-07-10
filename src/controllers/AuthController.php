<?php
/**
 * AuthController — Authentication + session management.
 * Supports local auth (PHP sessions) and Authelia proxy auth.
 */

declare(strict_types=1);

class AuthController
{
    // ── Auth guard ───────────────────────────────────────────────────────────
    public static function isAuthenticated(): bool
    {
        $authMode = getenv('AUTH_MODE') ?: 'local';

        if ($authMode === 'authelia') {
            $header = getenv('AUTH_HEADER') ?: 'Remote-User';
            $remoteUser = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $header))] ?? null;

            if ($remoteUser) {
                self::ensureAutheliaUser($remoteUser);
                $_SESSION['user_id']   = $remoteUser;
                $_SESSION['username']  = $remoteUser;
                $_SESSION['auth_mode'] = 'authelia';
                return true;
            }
            return false;
        }

        return isset($_SESSION['user_id']) && $_SESSION['auth_mode'] === 'local';
    }

    public static function currentUser(): ?string
    {
        return $_SESSION['username'] ?? null;
    }

    public static function currentRole(): string
    {
        return $_SESSION['role'] ?? 'user';
    }

    // ── Authelia: auto-create user on first header sight ────────────────────
    private static function ensureAutheliaUser(string $username): void
    {
        $user = Database::queryOne('SELECT id, role FROM users WHERE username = ?', [$username]);
        if (!$user) {
            $totalUsers = Database::queryOne('SELECT COUNT(*) as cnt FROM users')['cnt'];
            $role = ($totalUsers == 0) ? 'admin' : 'user';
            Database::insert(
                'INSERT INTO users (username, password, role) VALUES (?, ?, ?)',
                [$username, '', $role]
            );
        }
    }

    // ── Login form (GET /login) ─────────────────────────────────────────────
    public static function loginForm(array $query = [], array $body = []): void
    {
        if (self::isAuthenticated()) {
            header('Location: /', true, 302);
            exit;
        }

        // Redirect to setup if no users exist (local auth only)
        $authMode = getenv('AUTH_MODE') ?: 'local';
        if ($authMode === 'local') {
            $userCount = Database::queryOne('SELECT COUNT(*) as cnt FROM users')['cnt'];
            if ($userCount == 0) {
                header('Location: /setup', true, 302);
                exit;
            }
        }

        $error = $_SESSION['login_error'] ?? null;
        unset($_SESSION['login_error']);

        View::render('login', ['error' => $error, 'showNav' => false]);
    }

    // ── Login handler (POST /login) ─────────────────────────────────────────
    public static function login(array $query = [], array $body = []): void
    {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        $user = Database::queryOne(
            'SELECT id, username, password, role FROM users WHERE username = ?',
            [$username]
        );

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['role']      = $user['role'];
            $_SESSION['auth_mode'] = 'local';

            $redirect = $_SESSION['redirect_after_login'] ?? '/';
            unset($_SESSION['redirect_after_login']);
            header('Location: ' . $redirect, true, 302);
            exit;
        }

        $_SESSION['login_error'] = 'Invalid username or password';
        header('Location: /login', true, 302);
        exit;
    }

    // ── Logout ──────────────────────────────────────────────────────────────
    public static function logout(array $query = [], array $body = []): void
    {
        $authMode = getenv('AUTH_MODE') ?: 'local';
        session_destroy();

        if ($authMode === 'authelia') {
            // Redirect to Authelia logout, then back to our login
            $logoutUrl = getenv('AUTH_LOGOUT_URL') ?: '/';
            header('Location: ' . $logoutUrl, true, 302);
        } else {
            header('Location: /login', true, 302);
        }
        exit;
    }

    // ── Setup form (GET /setup) ─────────────────────────────────────────────
    public static function setupForm(array $query = [], array $body = []): void
    {
        $authMode = getenv('AUTH_MODE') ?: 'local';
        if ($authMode !== 'local') {
            http_response_code(404);
            echo "<h1>Not Found</h1>";
            exit;
        }

        $userCount = Database::queryOne('SELECT COUNT(*) as cnt FROM users')['cnt'];
        if ($userCount > 0) {
            header('Location: /login', true, 302);
            exit;
        }

        $error = $_SESSION['setup_error'] ?? null;
        unset($_SESSION['setup_error']);

        View::render('setup', ['error' => $error, 'showNav' => false]);
    }

    // ── Setup handler (POST /setup) ─────────────────────────────────────────
    public static function setup(array $query = [], array $body = []): void
    {
        $authMode = getenv('AUTH_MODE') ?: 'local';
        if ($authMode !== 'local') {
            http_response_code(403);
            exit;
        }

        $userCount = Database::queryOne('SELECT COUNT(*) as cnt FROM users')['cnt'];
        if ($userCount > 0) {
            http_response_code(403);
            exit;
        }

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (strlen($username) < 3 || strlen($password) < 8) {
            $_SESSION['setup_error'] = 'Username must be 3+ chars, password 8+ chars';
            header('Location: /setup', true, 302);
            exit;
        }

        Database::insert(
            'INSERT INTO users (username, password, role) VALUES (?, ?, ?)',
            [$username, password_hash($password, PASSWORD_BCRYPT), 'admin']
        );

        $user = Database::queryOne('SELECT id, username, role FROM users WHERE username = ?', [$username]);
        session_regenerate_id(true);
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['username']  = $user['username'];
        $_SESSION['role']      = $user['role'];
        $_SESSION['auth_mode'] = 'local';

        header('Location: /', true, 302);
        exit;
    }
}
