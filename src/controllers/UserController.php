<?php
/**
 * UserController — Admin-only user management.
 */

declare(strict_types=1);

class UserController
{
    // ── List users (GET /users) ────────────────────────────────────────────
    public static function list(array $query = [], array $body = []): void
    {
        if (($_SESSION['role'] ?? 'user') !== 'admin') {
            http_response_code(403);
            echo "<h1>403 Forbidden</h1><p>Admin access required.</p>";
            exit;
        }

        $error   = $_SESSION['user_error'] ?? null;
        $success = $_SESSION['user_success'] ?? null;
        unset($_SESSION['user_error'], $_SESSION['user_success']);

        $users = Database::query('SELECT id, username, role, created_at FROM users ORDER BY created_at DESC');

        View::render('users', [
            'username'    => $_SESSION['username'],
            'users'       => $users,
            'error'       => $error,
            'success'     => $success,
            'currentUser' => $_SESSION['username'],
            'pageTitle'   => 'Users',
            'navLinks'    => [
                ['url' => '/dashboard', 'label' => 'Dashboard'],
                ['url' => '/devices', 'label' => 'Devices'],
            ],
        ], 'layout');
    }

    // ── Create user ────────────────────────────────────────────────────────
    public static function create(array $query = [], array $body = []): void
    {
        if (($_SESSION['role'] ?? 'user') !== 'admin') {
            http_response_code(403); exit;
        }

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (strlen($username) < 3 || strlen($password) < 8) {
            $_SESSION['user_error'] = 'Username must be 3+ chars, password 8+ chars';
            header('Location: /users', true, 302); exit;
        }

        $existing = Database::queryOne('SELECT id FROM users WHERE username = ?', [$username]);
        if ($existing) {
            $_SESSION['user_error'] = "User '{$username}' already exists";
            header('Location: /users', true, 302); exit;
        }

        Database::insert(
            'INSERT INTO users (username, password, role) VALUES (?, ?, ?)',
            [$username, password_hash($password, PASSWORD_BCRYPT), 'user']
        );

        $_SESSION['user_success'] = "User '{$username}' created";
        header('Location: /users', true, 302); exit;
    }

    // ── Update user ────────────────────────────────────────────────────────
    public static function update(array $query = [], array $body = []): void
    {
        if (($_SESSION['role'] ?? 'user') !== 'admin') {
            http_response_code(403); exit;
        }

        $userId = (int) ($_POST['user_id'] ?? 0);
        $role   = $_POST['role'] ?? '';

        if (!in_array($role, ['admin', 'user'])) {
            $_SESSION['user_error'] = 'Invalid role';
            header('Location: /users', true, 302); exit;
        }

        $target = Database::queryOne('SELECT username FROM users WHERE id = ?', [$userId]);
        if (!$target) {
            $_SESSION['user_error'] = 'User not found';
            header('Location: /users', true, 302); exit;
        }

        Database::execute('UPDATE users SET role = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?', [$role, $userId]);
        $_SESSION['user_success'] = "User '{$target['username']}' role updated to '{$role}'";
        header('Location: /users', true, 302); exit;
    }

    // ── Delete user ────────────────────────────────────────────────────────
    public static function delete(array $query = [], array $body = []): void
    {
        if (($_SESSION['role'] ?? 'user') !== 'admin') {
            http_response_code(403); exit;
        }

        $userId = (int) ($_POST['user_id'] ?? 0);

        if ($userId === (int) $_SESSION['user_id']) {
            $_SESSION['user_error'] = 'You cannot delete yourself';
            header('Location: /users', true, 302); exit;
        }

        $target = Database::queryOne('SELECT username FROM users WHERE id = ?', [$userId]);
        if (!$target) {
            $_SESSION['user_error'] = 'User not found';
            header('Location: /users', true, 302); exit;
        }

        Database::execute('DELETE FROM devices WHERE user_id = ?', [$userId]);
        Database::execute('DELETE FROM users WHERE id = ?', [$userId]);

        $_SESSION['user_success'] = "User '{$target['username']}' deleted with all their data";
        header('Location: /users', true, 302); exit;
    }
}
