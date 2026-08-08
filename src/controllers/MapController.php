<?php
/**
 * MapController — Dashboard with interactive Leaflet.js map.
 */

declare(strict_types=1);

class MapController
{
    public static function dashboard(array $query = [], array $body = []): void
    {
        $username = $_SESSION['username'];
        $authMode = getenv('AUTH_MODE') ?: 'local';
        $isAdmin  = ($_SESSION['role'] ?? 'user') === 'admin';

        // Fetch user's devices for the filter dropdown (own devices only)
        if ($authMode === 'authelia') {
            $dbUser = Database::queryOne('SELECT id FROM users WHERE username = ?', [$username]);
            $devices = $dbUser ? Database::query('SELECT * FROM devices WHERE user_id = ? ORDER BY name', [$dbUser['id']]) : [];
        } else {
            $devices = Database::query('SELECT * FROM devices WHERE user_id = ? ORDER BY name', [$_SESSION['user_id']]);
        }

        View::render('dashboard', [
            'username'  => $username,
            'devices'   => $devices,
            'isAdmin'   => $isAdmin,
            'fullScreen' => true,
        ], 'layout');
    }
}
