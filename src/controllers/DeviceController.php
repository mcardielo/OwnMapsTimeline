<?php
/**
 * DeviceController — CRUD for tracking devices.
 */

declare(strict_types=1);

class DeviceController
{
    private const COLOR_PALETTE = [
        '#3b82f6', '#ef4444', '#22c55e', '#f59e0b', '#8b5cf6',
        '#ec4899', '#06b6d4', '#84cc16', '#f97316', '#6366f1',
    ];

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

        // Load shares for each device (who this device is shared with)
        $sharesByDevice = [];
        foreach ($devices as $d) {
            $sharesByDevice[$d['id']] = Database::query(
                'SELECT ds.shared_with_user_id, u.username FROM device_shares ds JOIN users u ON ds.shared_with_user_id = u.id WHERE ds.device_id = ?',
                [$d['id']]
            );
        }

        // Load devices shared WITH the current user
        $sharedWithMe = Database::query(
            'SELECT d.id, d.name, d.tid, d.color, u.username AS owner_name, ds.custom_color, ds.custom_name, ds.shared_with_user_id
             FROM device_shares ds
             JOIN devices d ON ds.device_id = d.id
             JOIN users u ON d.user_id = u.id
             WHERE ds.shared_with_user_id = ?
             ORDER BY d.name ASC',
            [self::resolveUserId()]
        );

        View::render('devices', [
            'username'      => $username,
            'devices'       => $devices,
            'sharesByDevice' => $sharesByDevice,
            'sharedWithMe'  => $sharedWithMe,
            'error'         => $error,
            'success'       => $success,
            'webhookBase'   => $webhookBase,
            'isAdmin'       => $isAdmin,
            'pageTitle'     => 'Devices',
            'navLinks'      => [
                ['url' => '/dashboard', 'label' => 'Dashboard'],
                ['url' => '/places', 'label' => 'Places'],
                ['url' => '/import', 'label' => 'Import'],
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

        // Auto-assign color: count existing devices, pick next palette color
        $count = Database::queryOne(
            'SELECT COUNT(*) AS c FROM devices WHERE user_id = ?',
            [$userId]
        );
        $color = self::COLOR_PALETTE[($count['c'] ?? 0) % count(self::COLOR_PALETTE)];

        Database::insert(
            'INSERT INTO devices (user_id, name, tid, webhook_token, color) VALUES (?, ?, ?, ?, ?)',
            [$userId, $name, $tid, $token, $color]
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

    // ── Update device color (POST /devices/color) ─────────────────────────
    public static function updateColor(array $query = [], array $body = []): void
    {
        $deviceId = $body['device_id'] ?? $_POST['device_id'] ?? '';
        $color    = $body['color'] ?? $_POST['color'] ?? '';

        if (strlen($deviceId) < 1 || strlen($color) < 1) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'device_id and color are required']);
            exit;
        }

        // Validate hex color
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid hex color']);
            exit;
        }

        $device = self::getUserDevice((int) $deviceId);
        if (!$device) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Device not found']);
            exit;
        }

        Database::execute(
            'UPDATE devices SET color = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$color, (int) $deviceId]
        );

        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'color' => $color]);
        exit;
    }

    // ── Share device (POST /devices/share) ──────────────────────────────────
    public static function shareDevice(array $query = [], array $body = []): void
    {
        $deviceId = $_POST['device_id'] ?? '';
        $username = trim($_POST['username'] ?? '');

        if (strlen($deviceId) < 1 || strlen($username) < 1) {
            $_SESSION['device_error'] = 'Device ID and username are required';
            header('Location: /devices', true, 302);
            exit;
        }

        $device = self::getUserDevice((int) $deviceId);
        if (!$device) {
            $_SESSION['device_error'] = 'Device not found';
            header('Location: /devices', true, 302);
            exit;
        }

        // Find target user
        $targetUser = Database::queryOne(
            'SELECT id, username FROM users WHERE username = ?',
            [$username]
        );
        if (!$targetUser) {
            $_SESSION['device_error'] = "User '{$username}' not found";
            header('Location: /devices', true, 302);
            exit;
        }

        // Can't share with yourself
        if ((int) $targetUser['id'] === self::resolveUserId()) {
            $_SESSION['device_error'] = 'You cannot share a device with yourself';
            header('Location: /devices', true, 302);
            exit;
        }

        // Check if already shared
        $existing = Database::queryOne(
            'SELECT id FROM device_shares WHERE device_id = ? AND shared_with_user_id = ?',
            [(int) $deviceId, (int) $targetUser['id']]
        );
        if ($existing) {
            $_SESSION['device_error'] = "Device already shared with '{$username}'";
            header('Location: /devices', true, 302);
            exit;
        }

        Database::insert(
            'INSERT INTO device_shares (device_id, shared_with_user_id) VALUES (?, ?)',
            [(int) $deviceId, (int) $targetUser['id']]
        );

        $_SESSION['device_success'] = "Device '{$device['name']}' shared with '{$username}'";
        header('Location: /devices', true, 302);
        exit;
    }

    // ── Unshare device (POST /devices/unshare) ──────────────────────────────
    public static function unshareDevice(array $query = [], array $body = []): void
    {
        $deviceId   = $_POST['device_id'] ?? '';
        $shareUserId = $_POST['share_user_id'] ?? '';

        if (strlen($deviceId) < 1 || strlen($shareUserId) < 1) {
            $_SESSION['device_error'] = 'Device ID and share user ID are required';
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
            'DELETE FROM device_shares WHERE device_id = ? AND shared_with_user_id = ?',
            [(int) $deviceId, (int) $shareUserId]
        );

        $_SESSION['device_success'] = 'Share removed';
        header('Location: /devices', true, 302);
        exit;
    }

    // ── Unshare self (POST /devices/unshare-self) ───────────────────────────
    public static function unshareSelf(array $query = [], array $body = []): void
    {
        $deviceId = $_POST['device_id'] ?? '';

        if (strlen($deviceId) < 1) {
            $_SESSION['device_error'] = 'Device ID is required';
            header('Location: /devices', true, 302);
            exit;
        }

        $userId = self::resolveUserId();

        Database::execute(
            'DELETE FROM device_shares WHERE device_id = ? AND shared_with_user_id = ?',
            [(int) $deviceId, $userId]
        );

        $_SESSION['device_success'] = 'Stopped viewing shared device';
        header('Location: /devices', true, 302);
        exit;
    }

    // ── Update share color/name (POST /devices/share-color) ──────────────
    public static function updateShareColor(array $query = [], array $body = []): void
    {
        $deviceId   = $body['device_id'] ?? $_POST['device_id'] ?? '';
        $color      = $body['color'] ?? $_POST['color'] ?? '';
        $customName = $body['custom_name'] ?? $_POST['custom_name'] ?? null;

        if (strlen($deviceId) < 1) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'device_id is required']);
            exit;
        }

        $userId = self::resolveUserId();

        // Verify the share exists
        $share = Database::queryOne(
            'SELECT id FROM device_shares WHERE device_id = ? AND shared_with_user_id = ?',
            [(int) $deviceId, $userId]
        );
        if (!$share) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Share not found']);
            exit;
        }

        // Build UPDATE dynamically based on what was provided
        $fields = [];
        $params = [];
        if (strlen($color) > 0) {
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Invalid hex color']);
                exit;
            }
            $fields[] = 'custom_color = ?';
            $params[] = $color;
        }
        if ($customName !== null) {
            $trimmedName = trim($customName);
            if (strlen($trimmedName) > 0) {
                $fields[] = 'custom_name = ?';
                $params[] = $trimmedName;
            } else {
                $fields[] = 'custom_name = NULL';
            }
        }
        if (empty($fields)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Nothing to update']);
            exit;
        }
        $params[] = (int) $deviceId;
        $params[] = $userId;

        Database::execute(
            'UPDATE device_shares SET ' . implode(', ', $fields) . ' WHERE device_id = ? AND shared_with_user_id = ?',
            $params
        );

        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
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
