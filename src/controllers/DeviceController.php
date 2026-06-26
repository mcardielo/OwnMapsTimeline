<?php
/**
 * DeviceController — CRUD for tracking devices.
 */

declare(strict_types=1);

class DeviceController
{
    // ── List devices (GET /devices) ─────────────────────────────────────────
    public static function list(array $query = [], array $body = []): void
    {
        $username = $_SESSION['username'];
        $authMode = getenv('AUTH_MODE') ?: 'local';
        $isAdmin  = ($_SESSION['role'] ?? 'user') === 'admin';

        // Fetch user's devices
        if ($authMode === 'authelia') {
            $dbUser = Database::queryOne('SELECT id FROM users WHERE username = ?', [$username]);
            $devices = $dbUser ? Database::query('SELECT * FROM devices WHERE user_id = ? ORDER BY created_at DESC', [$dbUser['id']]) : [];
        } else {
            $devices = Database::query('SELECT * FROM devices WHERE user_id = ? ORDER BY created_at DESC', [$_SESSION['user_id']]);
        }

        $error   = $_SESSION['device_error'] ?? null;
        $success = $_SESSION['device_success'] ?? null;
        unset($_SESSION['device_error'], $_SESSION['device_success']);

        // Detect real scheme behind reverse proxy
        $scheme = 'http';
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'];
        } elseif (($_SERVER['HTTPS'] ?? 'off') === 'on') {
            $scheme = 'https';
        }
        $host   = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
        $webhookBase = "{$scheme}://{$host}/webhook";

        View::render('devices', [
            'username'    => $username,
            'devices'     => $devices,
            'error'       => $error,
            'success'     => $success,
            'webhookBase' => $webhookBase,
            'isAdmin'     => $isAdmin,
            'pageTitle'   => 'Devices',
            'navLinks'    => [
                ['url' => '/dashboard', 'label' => 'Dashboard'],
                $isAdmin ? ['url' => '/users', 'label' => 'Users'] : null,
            ],
        ], 'layout');
    }

    // ── Create device (POST /devices/create) ────────────────────────────────
    public static function create(array $query = [], array $body = []): void
    {
        $name = trim($_POST['name'] ?? '');
        $tid  = trim($_POST['tid'] ?? '');

        if (strlen($name) < 1 || strlen($tid) < 1) {
            $_SESSION['device_error'] = 'Name and TID are required';
            header('Location: /devices', true, 302);
            exit;
        }

        if (!preg_match('/^[A-Za-z0-9_-]+$/', $tid)) {
            $_SESSION['device_error'] = 'TID can only contain letters, numbers, hyphens, and underscores';
            header('Location: /devices', true, 302);
            exit;
        }

        $userId = self::resolveUserId();

        $existing = Database::queryOne(
            'SELECT id FROM devices WHERE user_id = ? AND tid = ?',
            [$userId, $tid]
        );
        if ($existing) {
            $_SESSION['device_error'] = "A device with TID '{$tid}' already exists";
            header('Location: /devices', true, 302);
            exit;
        }

        $token = bin2hex(random_bytes(16));

        Database::insert(
            'INSERT INTO devices (user_id, name, tid, webhook_token) VALUES (?, ?, ?, ?)',
            [$userId, $name, $tid, $token]
        );

        $_SESSION['device_success'] = "Device '{$name}' created! Use the QR code or URL to configure OwnTracks.";
        header('Location: /devices', true, 302);
        exit;
    }

    // ── Update device (POST /devices/update) ────────────────────────────────
    public static function update(array $query = [], array $body = []): void
    {
        $deviceId = $_POST['device_id'] ?? '';
        $newName  = trim($_POST['name'] ?? '');

        if (strlen($deviceId) < 1 || strlen($newName) < 1) {
            $_SESSION['device_error'] = 'Device ID and name are required';
            header('Location: /devices', true, 302);
            exit;
        }

        $device = self::getUserDevice((int) $deviceId);
        if (!$device) {
            $_SESSION['device_error'] = 'Device not found';
            header('Location: /devices', true, 302);
            exit;
        }

        Database::execute(
            'UPDATE devices SET name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$newName, (int) $deviceId]
        );

        $_SESSION['device_success'] = "Device renamed to '{$newName}'";
        header('Location: /devices', true, 302);
        exit;
    }

    // ── Update device config (POST /devices/config) ─────────────────────────
    public static function updateConfig(array $query = [], array $body = []): void
    {
        $deviceId   = $body['device_id'] ?? $_POST['device_id'] ?? '';
        $configJson = $body['config_json'] ?? $_POST['config_json'] ?? '';

        if (strlen($deviceId) < 1 || strlen($configJson) < 1) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'device_id and config_json are required']);
            exit;
        }

        $device = self::getUserDevice((int) $deviceId);
        if (!$device) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Device not found']);
            exit;
        }

        // Validate it's valid JSON
        $config = json_decode($configJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
            exit;
        }

        Database::execute(
            'UPDATE devices SET config_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$configJson, (int) $deviceId]
        );

        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
        exit;
    }

    // ── Delete device (POST /devices/delete) ────────────────────────────────
    public static function delete(array $query = [], array $body = []): void
    {
        $deviceId = $_POST['device_id'] ?? '';

        if (strlen($deviceId) < 1) {
            $_SESSION['device_error'] = 'Device ID is required';
            header('Location: /devices', true, 302);
            exit;
        }

        $device = self::getUserDevice((int) $deviceId);
        if (!$device) {
            $_SESSION['device_error'] = 'Device not found';
            header('Location: /devices', true, 302);
            exit;
        }

        Database::execute('DELETE FROM devices WHERE id = ?', [(int) $deviceId]);
        $_SESSION['device_success'] = "Device '{$device['name']}' deleted";
        header('Location: /devices', true, 302);
        exit;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────
    private static function resolveUserId(): int
    {
        $authMode = getenv('AUTH_MODE') ?: 'local';
        if ($authMode === 'authelia') {
            $dbUser = Database::queryOne('SELECT id FROM users WHERE username = ?', [$_SESSION['username']]);
            return $dbUser ? (int) $dbUser['id'] : 0;
        }
        return (int) $_SESSION['user_id'];
    }

    private static function getUserDevice(int $deviceId): ?array
    {
        return Database::queryOne(
            'SELECT * FROM devices WHERE id = ? AND user_id = ?',
            [$deviceId, self::resolveUserId()]
        );
    }
}
