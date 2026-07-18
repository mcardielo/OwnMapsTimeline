<?php
/**
 * PlaceController — Manage detected places (stay points).
 * 
 * Routes:
 *   GET  /places          — list (two sections: named / unnamed)
 *   GET  /places/{id}     — detail with visit history
 *   POST /places/{id}/rename — name/renam a place
 *   POST /places/{id}/delete — delete a place
 *   GET  /api/places      — JSON markers for the map
 *   POST /api/places/detect — trigger background detection
 *   GET  /api/places/status — check detection progress
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/PlaceDetector.php';

class PlaceController
{
    // ── GET /places ────────────────────────────────────────────────────────
    public static function list(array $query = [], array $body = []): void
    {
        $userId = self::getUserId();
        if (!$userId) {
            header('Location: /login');
            exit;
        }

        // Check if detection is running
        $detectStatus = self::getDetectStatus($userId);
        $settings = PlaceDetector::getSettings($userId);

        // Load existing places from DB (no detection here!)
        $places = PlaceDetector::getExistingPlaces($userId);

        // Get devices for filter
        $devices = Database::query(
            'SELECT id, name FROM devices WHERE user_id = ? ORDER BY name ASC',
            [$userId]
        );

        // Split: named vs unnamed
        $named   = [];
        $unnamed = [];
        foreach ($places as $p) {
            if (!empty($p['name'])) {
                $named[] = $p;
            } else {
                $unnamed[] = $p;
            }
        }

        // Named: alphabetical
        usort($named, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        // Unnamed: by visit_count desc
        usort($unnamed, function ($a, $b) {
            return $b['visit_count'] <=> $a['visit_count'];
        });

        $isAdmin = ($_SESSION['role'] ?? 'user') === 'admin';

        View::render('places', [
            'username'      => $_SESSION['username'],
            'named'          => $named,
            'unnamed'        => $unnamed,
            'isAdmin'        => $isAdmin,
            'detectStatus'   => $detectStatus,
            'settings'       => $settings,
            'devices'        => $devices,
            'navLinks'       => [
                ['url' => '/dashboard', 'label' => 'Dashboard'],
                ['url' => '/devices', 'label' => 'Devices'],
                ['url' => '/import', 'label' => 'Import'],
            ],
        ], 'layout');
    }

    // ── GET /places/{id} ───────────────────────────────────────────────────
    public static function detail(array $query = [], array $body = []): void
    {
        $userId = self::getUserId();
        if (!$userId) {
            header('Location: /login');
            exit;
        }

        $placeId = (int) ($query['id'] ?? 0);
        if (!$placeId) {
            http_response_code(400);
            echo "<h1>400 Bad Request</h1><p>Missing place ID.</p>";
            exit;
        }

        $place = PlaceDetector::getPlace($placeId, $userId);
        if (!$place) {
            http_response_code(404);
            echo "<h1>404 Not Found</h1><p>Place not found.</p>";
            exit;
        }

        $visits = PlaceDetector::getVisits($placeId, $userId);

        // Sync visit_count with actual visits from getVisits
        $actualCount = count($visits);
        if ((int) $place['visit_count'] !== $actualCount) {
            PlaceDetector::updateVisitCount($placeId, $actualCount);
            $place['visit_count'] = $actualCount;
        }

        $isAdmin = ($_SESSION['role'] ?? 'user') === 'admin';

        View::render('place_detail', [
            'username'  => $_SESSION['username'],
            'place'      => $place,
            'visits'     => $visits,
            'isAdmin'    => $isAdmin,
            'navLinks'   => [
                ['url' => '/dashboard', 'label' => 'Dashboard'],
                ['url' => '/devices', 'label' => 'Devices'],
                ['url' => '/places', 'label' => 'Places'],
            ],
        ], 'layout');
    }

    // ── POST /places/{id}/rename ───────────────────────────────────────────
    public static function rename(array $query = [], array $body = []): void
    {
        $userId = self::getUserId();
        if (!$userId) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
            exit;
        }

        $placeId = (int) ($query['id'] ?? $_POST['id'] ?? 0);
        $name = trim($body['name'] ?? $_POST['name'] ?? '');

        if (!$placeId) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Missing place ID']);
            exit;
        }

        $place = PlaceDetector::getPlace($placeId, $userId);
        if (!$place) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Place not found']);
            exit;
        }

        Database::execute(
            'UPDATE places SET name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?',
            [$name ?: null, $placeId, $userId]
        );

        $redirect = $_POST['redirect'] ?? '/places';
        header('Location: ' . $redirect, true, 302);
        exit;
    }

    // ── POST /places/{id}/recalculate ──────────────────────────────────────
    public static function recalculate(array $query = [], array $body = []): void
    {
        $userId = self::getUserId();
        if (!$userId) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
            exit;
        }

        $placeId = (int) ($query['id'] ?? $_POST['id'] ?? 0);
        $radius = (float) ($body['radius'] ?? $_POST['radius'] ?? 0);

        if (!$placeId || $radius < 10) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Invalid place ID or radius']);
            exit;
        }

        $place = PlaceDetector::getPlace($placeId, $userId);
        if (!$place) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Place not found']);
            exit;
        }

        // Recalculate centroid + radius with all device points within the new radius
        $newCenter = PlaceDetector::recalculateCentroid($placeId, $userId, $radius);

        if ($newCenter) {
            // Update lat, lon, and radius with recalculated values
            Database::execute(
                'UPDATE places SET lat = ?, lon = ?, radius = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?',
                [$newCenter['lat'], $newCenter['lon'], $newCenter['radius'], $placeId, $userId]
            );
        } else {
            // Not enough points to recalculate — just update the radius
            Database::execute(
                'UPDATE places SET radius = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?',
                [$radius, $placeId, $userId]
            );
        }

        // Recalculate visits with new radius (and possibly new centroid)
        $visits = PlaceDetector::getVisits($placeId, $userId);

        // Update visit_count and total_time
        $visitCount = count($visits);
        $totalTime = array_sum(array_column($visits, 'duration'));
        if ($visitCount > 0) {
            $firstSeen = min(array_column($visits, 'start_tst'));
            $lastSeen = max(array_column($visits, 'end_tst'));
            Database::execute(
                'UPDATE places SET visit_count = ?, total_time = ?, first_seen = ?, last_seen = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
                [$visitCount, $totalTime, $firstSeen, $lastSeen, $placeId]
            );
        } else {
            Database::execute(
                'UPDATE places SET visit_count = 0, total_time = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
                [$placeId]
            );
        }

        header('Location: /places/' . $placeId, true, 302);
        exit;
    }

    // ── POST /places/{id}/delete ───────────────────────────────────────────
    public static function delete(array $query = [], array $body = []): void
    {
        $userId = self::getUserId();
        if (!$userId) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
            exit;
        }

        $placeId = (int) ($query['id'] ?? $_POST['id'] ?? 0);
        if (!$placeId) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Missing place ID']);
            exit;
        }

        $place = PlaceDetector::getPlace($placeId, $userId);
        if (!$place) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Place not found']);
            exit;
        }

        Database::execute('DELETE FROM places WHERE id = ? AND user_id = ?', [$placeId, $userId]);

        header('Location: /places', true, 302);
        exit;
    }

    // ── GET /api/places ────────────────────────────────────────────────────
    public static function apiList(array $query = [], array $body = []): void
    {
        $userId = self::getUserId();
        if (!$userId) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Not authenticated']);
            exit;
        }

        // Filter by device_id if provided
        $deviceId = isset($query['device_id']) ? (int) $query['device_id'] : null;
        $places = PlaceDetector::getExistingPlaces($userId, $deviceId);

        header('Content-Type: application/json');
        echo json_encode([
            'ok'     => true,
            'places' => $places,
        ]);
        exit;
    }

    // ── POST /api/places/detect ────────────────────────────────────────────
    public static function detect(array $query = [], array $body = []): void
    {
        $userId = self::getUserId();
        if (!$userId) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
            exit;
        }
        // Check if already running
        $status = self::getDetectStatus($userId);
        if ($status['status'] === 'running') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'message' => 'Detection already running', 'status' => $status]);
            exit;
        }

        // mode: 'incremental' (default) or 'redetect' (clean slate)
        $mode = $body['mode'] ?? $_POST['mode'] ?? $query['mode'] ?? 'incremental';

        $progressFile = sys_get_temp_dir() . '/places_detect_' . $userId . '.json';
        @unlink($progressFile);
        @unlink(sys_get_temp_dir() . '/places_debug_' . $userId . '.log');
        @unlink(sys_get_temp_dir() . '/places_debug_dbscan.log');

        if ($mode === 'redetect') {
            // Only delete unnamed places — named places are preserved
            Database::execute("DELETE FROM places WHERE user_id = ? AND (name IS NULL OR name = '')", [$userId]);
            Database::execute('UPDATE places_meta SET last_analyzed_at = 0 WHERE user_id = ?', [$userId]);
        }

        // Launch background PHP process (setsid to fully detach from PHP-FPM)
        $scriptPath = __DIR__ . '/../../scripts/detect_places.php';
        $logFile = sys_get_temp_dir() . '/places_detect_' . $userId . '.log';
        // PHP_BINARY returns php-fpm binary when running under FPM.
        // We need the CLI binary instead. In php:*-fpm-alpine images,
        // the CLI binary is at /usr/local/bin/php (same path, different binary).
        $phpBin = PHP_BINARY;
        if (strpos($phpBin, 'php-fpm') !== false) {
            // Replace php-fpm with php (CLI)
            $phpBin = str_replace('php-fpm', 'php', $phpBin);
        }
        // Fallback: try common paths
        if (!file_exists($phpBin)) {
            foreach (['/usr/local/bin/php', '/usr/bin/php'] as $candidate) {
                if (file_exists($candidate)) {
                    $phpBin = $candidate;
                    break;
                }
            }
        }
        // setsid creates a new session, detaching the process from PHP-FPM/supervisor
        $cmd = "setsid " . escapeshellarg($phpBin) . " " . escapeshellarg($scriptPath) . " " . escapeshellarg((string) $userId) . " " . escapeshellarg($logFile) . " " . escapeshellarg($mode) . " > " . escapeshellarg($logFile) . " 2>&1 < /dev/null &";
        exec($cmd);

        // Small delay to let the process write its initial status
        usleep(500000);

        // Verify it started
        $newStatus = self::getDetectStatus($userId);
        $started = ($newStatus['status'] ?? 'idle') === 'running';

        header('Content-Type: application/json');
        echo json_encode([
            'ok' => $started,
            'message' => $started ? 'Detection started in background' : 'Failed to start detection (check logs)',
            'log_path' => $logFile,
            'status' => $newStatus,
        ]);
        exit;
    }

    // ── GET /api/places/status ─────────────────────────────────────────────
    public static function status(array $query = [], array $body = []): void
    {
        $userId = self::getUserId();
        if (!$userId) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Not authenticated']);
            exit;
        }

        $status = self::getDetectStatus($userId);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'status' => $status]);
        exit;
    }

    // ── POST /places/settings ───────────────────────────────────────────────
    public static function saveSettings(array $query = [], array $body = []): void
    {
        $userId = self::getUserId();
        if (!$userId) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
            exit;
        }
        // min_duration comes in minutes from the form, convert to seconds
        $minDurationMin = (int) ($body['min_duration'] ?? $_POST['min_duration'] ?? 10);
        $params = [
            'epsilon'         => (float) ($body['epsilon'] ?? $_POST['epsilon'] ?? 50),
            'min_visits'      => (int) ($body['min_visits'] ?? $_POST['min_visits'] ?? 2),
            'min_duration'    => $minDurationMin * 60,
            'min_points_visit' => (int) ($body['min_points_visit'] ?? $_POST['min_points_visit'] ?? 5),
            'merge_distance'  => (float) ($body['merge_distance'] ?? $_POST['merge_distance'] ?? 70),
            'max_radius'      => (float) ($body['max_radius'] ?? $_POST['max_radius'] ?? 100),
            'merge_gap'       => (int) ($body['merge_gap'] ?? $_POST['merge_gap'] ?? 10) * 60,
        ];

        PlaceDetector::saveSettings($userId, $params);

        header('Location: /places', true, 302);
        exit;
    }

    // ── Helper: resolve user ID ─────────────────────────────────────────────
    private static function getUserId(): ?int
    {
        $authMode = getenv('AUTH_MODE') ?: 'local';
        $username = $_SESSION['username'] ?? null;
        if (!$username) return null;

        if ($authMode === 'authelia') {
            $dbUser = Database::queryOne('SELECT id FROM users WHERE username = ?', [$username]);
            return $dbUser ? (int) $dbUser['id'] : null;
        }
        return (int) ($_SESSION['user_id'] ?? 0) ?: null;
    }

    // ── GET /api/places/log ────────────────────────────────────────────────
    public static function log(array $query = [], array $body = []): void
    {
        $userId = self::getUserId();
        if (!$userId) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Not authenticated']);
            exit;
        }

        $logFile = sys_get_temp_dir() . '/places_detect_' . $userId . '.log';
        $logContent = file_exists($logFile) ? file_get_contents($logFile) : 'No log file found';

        header('Content-Type: text/plain');
        echo $logContent;
        exit;
    }

    // ── GET /api/places/cron-log ────────────────────────────────────────────
    public static function cronLog(array $query = [], array $body = []): void
    {
        $userId = self::getUserId();
        if (!$userId) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Not authenticated']);
            exit;
        }

        $cronLog = sys_get_temp_dir() . '/places_cron.log';
        $content = file_exists($cronLog) ? file_get_contents($cronLog) : '';
        $lines = array_filter(explode("
", trim($content)));
        // Last 20 entries, newest first
        $lines = array_reverse(array_slice($lines, -20));

        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'entries' => array_values($lines)]);
        exit;
    }

    // ── GET /api/places/debug ────────────────────────────────────────────────
    public static function debugLog(array $query = [], array $body = []): void
    {
        $userId = self::getUserId();
        if (!$userId) {
            http_response_code(403);
            header('Content-Type: text/plain');
            echo 'Not authenticated';
            exit;
        }

        $debugFile = sys_get_temp_dir() . '/places_debug_' . $userId . '.log';
        $dbscanFile = sys_get_temp_dir() . '/places_debug_dbscan.log';
        $content = '';
        if (file_exists($debugFile)) $content .= file_get_contents($debugFile);
        if (file_exists($dbscanFile)) {
            $content .= "\n=== DBSCAN LOG ===\n";
            $content .= file_get_contents($dbscanFile);
        }
        if (empty($content)) $content = 'No debug log found';

        header('Content-Type: text/plain');
        echo $content;
        exit;
    }

    // ── Helper: read detection status from temp file ────────────────────────
    private static function getDetectStatus(int $userId): array
    {
        $progressFile = sys_get_temp_dir() . '/places_detect_' . $userId . '.json';
        if (file_exists($progressFile)) {
            $data = json_decode(file_get_contents($progressFile), true);
            if ($data) {
                // Auto-clean old "done" statuses (older than 5 min)
                if ($data['status'] === 'done' && isset($data['finished_at'])) {
                    if (time() - $data['finished_at'] > 300) {
                        @unlink($progressFile);
                        return ['status' => 'idle'];
                    }
                }
                // Stale running check (older than 10 min = probably timed out)
                if ($data['status'] === 'running' && isset($data['started_at'])) {
                    if (time() - $data['started_at'] > 600) {
                        @unlink($progressFile);
                        return ['status' => 'idle', 'message' => 'Previous detection timed out'];
                    }
                }
                return $data;
            }
        }
        return ['status' => 'idle'];
    }
}