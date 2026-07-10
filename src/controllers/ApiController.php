<?php
/**
 * ApiController — Internal JSON API for the map frontend.
 * Protected by auth.
 */

declare(strict_types=1);

class ApiController
{
    // ── GET /api/locations ──────────────────────────────────────────────────
    public static function locations(array $query = [], array $body = []): void
    {
        $authMode = getenv('AUTH_MODE') ?: 'local';
        $username = $_SESSION['username'];

        if ($authMode === 'authelia') {
            $dbUser = Database::queryOne('SELECT id FROM users WHERE username = ?', [$username]);
            $userId = $dbUser['id'] ?? null;
        } else {
            $userId = $_SESSION['user_id'];
        }

        if (!$userId) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'User not found']);
            exit;
        }

        // ── Filters ──────────────────────────────────────────────────────────
        $deviceId  = $query['device_id'] ?? null;
        $from      = $query['from'] ?? null;
        $to        = $query['to'] ?? null;
        $range     = $query['range'] ?? null;
        $tag       = $query['tag'] ?? null;

        // Build WHERE
        $where  = 'l.device_id IN (SELECT id FROM devices WHERE user_id = ?)';
        $params = [$userId];

        if ($deviceId && $deviceId !== 'all') {
            $where .= ' AND l.device_id = ?';
            $params[] = (int) $deviceId;
        }

        // Tag filter
        if ($tag && $tag !== '') {
            $where .= ' AND l.tag = ?';
            $params[] = $tag;
        }

        // Time range: from/to timestamps take priority
        if ($from && is_numeric($from)) {
            $where .= ' AND l.tst >= ?';
            $params[] = (int) $from;
        }
        if ($to && is_numeric($to)) {
            $where .= ' AND l.tst <= ?';
            $params[] = (int) $to;
        }

        // Fallback: preset range strings (only if from/to not set)
        $timeRanges = [
            '1h'  => 3600,
            '6h'  => 6 * 3600,
            '24h' => 24 * 3600,
            '7d'  => 7 * 86400,
            '30d' => 30 * 86400,
        ];
        if (!$from && !$to && $range) {
            if (isset($timeRanges[$range])) {
                $since = time() - $timeRanges[$range];
                $where .= ' AND l.tst >= ?';
                $params[] = $since;
            }
        }

        // ── Query ────────────────────────────────────────────────────────────
        $sql = "SELECT l.id, l.device_id, l.lat, l.lon, l.tst, l.acc, l.alt, l.vel, l.batt, l.bs, l.conn, l.t, l.vac, l.tag, l.poi, l.poi_imagename, d.name AS device_name, d.tid, d.color
                FROM locations l
                JOIN devices d ON l.device_id = d.id
                WHERE {$where}
                ORDER BY l.tst ASC";

        $rows = Database::query($sql, $params);
        $originalCount = count($rows);

        // Spatial downsample — keep points ≥ 30m apart, adapt threshold if > 5000
        $rows = self::downsample($rows);

        // Include device's full data range (for auto-populating date pickers)
        $rangeData = null;
        if ($deviceId && $deviceId !== 'all') {
            $rangeData = Database::queryOne(
                'SELECT MIN(l.tst) AS min_tst, MAX(l.tst) AS max_tst FROM locations l WHERE l.device_id = ?',
                [(int) $deviceId]
            );
        }

        // POIs: always query all POI locations (device-independent)
        $pois = Database::query(
            "SELECT l.id, l.lat, l.lon, l.tst, l.acc, l.poi, l.poi_imagename, d.name AS device_name, d.tid, d.color
             FROM locations l
             JOIN devices d ON l.device_id = d.id
             WHERE l.poi IS NOT NULL AND l.poi != ''
               AND l.device_id IN (SELECT id FROM devices WHERE user_id = ?)
             ORDER BY l.tst DESC",
            [$userId]
        );

        // Tags: distinct tags for this user (device-dependent)
        $tagWhere = 'd.user_id = ? AND l.tag IS NOT NULL AND l.tag != \'\'';
        $tagParams = [$userId];
        if ($deviceId && $deviceId !== 'all') {
            $tagWhere .= ' AND l.device_id = ?';
            $tagParams[] = (int) $deviceId;
        }
        $tags = Database::query(
            "SELECT DISTINCT l.tag
             FROM locations l
             JOIN devices d ON l.device_id = d.id
             WHERE {$tagWhere}
             ORDER BY l.tag ASC",
            $tagParams
        );

        // Tag range: min/max tst for the filtered tag (for auto-setting date pickers)
        $tagRange = null;
        if ($tag && $tag !== '') {
            $tagRange = Database::queryOne(
                "SELECT MIN(l.tst) AS min_tst, MAX(l.tst) AS max_tst
                 FROM locations l
                 JOIN devices d ON l.device_id = d.id
                 WHERE d.user_id = ? AND l.tag = ?",
                [$userId, $tag]
            );
        }

        header('Content-Type: application/json');
        echo json_encode([
            'ok'             => true,
            'points'         => $rows,
            'pois'           => $pois,
            'tags'           => array_column($tags, 'tag'),
            'tag_range'      => $tagRange,
            'count'          => count($rows),
            'original_count' => $originalCount,
            'range'          => $rangeData,
        ]);
        exit;
    }

    // ── GET /api/device-config ─────────────────────────────────────────────
    // Returns Owntracks .otrc JSON for remote config via owntracks:///config?url=...
    // No auth required — validated by tid + token in query
    public static function deviceConfig(array $query = [], array $body = []): void
    {
        $tid   = $query['tid']   ?? null;
        $token = $query['token'] ?? null;

        if (!$tid || !$token) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Missing tid or token']);
            exit;
        }

        // Find device by tid + token
        $device = Database::queryOne(
            'SELECT d.*, u.username FROM devices d JOIN users u ON d.user_id = u.id WHERE d.tid = ? AND d.webhook_token = ?',
            [$tid, $token]
        );

        if (!$device) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Device not found']);
            exit;
        }

        // Build webhook URL
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'];
        }
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $webhookUrl = "{$scheme}://{$host}/webhook?tid={$tid}&token={$token}";

        // Configurable params (with recommended settings)
        $positions             = isset($query['positions'])             ? (int) $query['positions']             : 1000;
        $ranging               = isset($query['ranging'])               ? filter_var($query['ranging'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true : true;
        $locked                = isset($query['locked'])                ? filter_var($query['locked'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false : false;
        $monitoring            = isset($query['monitoring'])            ? (int) $query['monitoring']            : 2;
        $days                  = isset($query['days'])                  ? (int) $query['days']                  : -1;
        $allowRemoteLocation   = isset($query['allowRemoteLocation'])   ? filter_var($query['allowRemoteLocation'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true : true;
        $adapt                 = isset($query['adapt'])                 ? (int) $query['adapt']                 : 10;
        $locatorInterval       = isset($query['locatorInterval'])       ? (int) $query['locatorInterval']       : 60;
        $locatorDisplacement   = isset($query['locatorDisplacement'])   ? (int) $query['locatorDisplacement']   : 100;
        $downgrade             = isset($query['downgrade'])             ? (int) $query['downgrade']             : 20;
        $maxHistory            = isset($query['maxHistory'])            ? (int) $query['maxHistory']            : 0;
        $ignoreStaleLocations  = isset($query['ignoreStaleLocations'])  ? (int) $query['ignoreStaleLocations']  : 0;
        $ignoreInaccurateLocations = isset($query['ignoreInaccurateLocations']) ? (int) $query['ignoreInaccurateLocations'] : 0;

        $config = [
            '_type'          => 'configuration',
            'mode'           => 3,          // HTTP
            'url'            => $webhookUrl,
            'tid'            => $tid,
            'deviceId'       => $device['name'] ?? $tid,
            'username'       => $device['username'] ?? '',
            'auth'           => false,
            'usePassword'    => false,
            'password'       => '',
            'extendedData'   => true,
            'cmd'             => true,
            'sub'             => true,
            'pubTopicBase'    => 'owntracks/http/' . strtoupper(substr($tid, 0, 2)),
            'encryptionKey'   => '',
            'httpHeaders'     => '',
            'osmTemplate'     => '',
            'osmCopyright'    => '',
            // User-configurable
            'positions'             => $positions,
            'maxHistory'            => $maxHistory,
            'ranging'               => $ranging,
            'locked'                => $locked,
            'monitoring'            => $monitoring,
            'days'                  => $days,
            'allowRemoteLocation'   => $allowRemoteLocation,
            'adapt'                 => $adapt,
            'locatorInterval'       => $locatorInterval,
            'locatorDisplacement'   => $locatorDisplacement,
            'downgrade'             => $downgrade,
            'ignoreStaleLocations'  => $ignoreStaleLocations,
            'ignoreInaccurateLocations' => $ignoreInaccurateLocations,
        ];

        header('Content-Type: application/json');
        echo json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ── GET /api/poi-image ──────────────────────────────────────────────────
    /**
     * Serve POI image on-demand from the raw_data JSON.
     * Protected (no auth exception needed — browser sends session/cookies on <img> load).
     *
     * Query params: id (location ID)
     */
    public static function poiImage(array $query = [], array $body = []): void
    {
        $id = $query['id'] ?? null;
        if (!$id || !is_numeric($id)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Missing or invalid id']);
            exit;
        }

        // Resolve user_id (same pattern as locations())
        $authMode = getenv('AUTH_MODE') ?: 'local';
        $username = $_SESSION['username'];

        if ($authMode === 'authelia') {
            $dbUser = Database::queryOne('SELECT id FROM users WHERE username = ?', [$username]);
            $userId = $dbUser['id'] ?? null;
        } else {
            $userId = $_SESSION['user_id'];
        }

        if (!$userId) {
            http_response_code(403);
            exit;
        }

        // Validate that the POI belongs to a device owned by this user
        $row = Database::queryOne(
            'SELECT l.raw_data FROM locations l
             JOIN devices d ON l.device_id = d.id
             WHERE l.id = ? AND d.user_id = ?',
            [(int) $id, $userId]
        );

        if (!$row || !$row['raw_data']) {
            http_response_code(404);
            exit;
        }

        $data = json_decode($row['raw_data'], true);
        if (!$data || empty($data['image'])) {
            http_response_code(404);
            exit;
        }

        $image = $data['image'];
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=31536000, immutable');
        echo base64_decode($image);
        exit;
    }

    // ── Spatial downsampling ────────────────────────────────────────────────

    /**
     * Haversine distance in meters between two lat/lon points.
     */
    private static function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $earthRadius * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /**
     * Distance-based spatial downsampling with adaptive threshold.
     * Keeps a point only if it's ≥ $threshold meters from the previous kept point.
     * If the result still exceeds $maxCount, increases threshold and retries (max 3 times).
     */
    private static function downsample(array $points, float $threshold = 30, int $maxCount = 5000): array
    {
        if (count($points) <= $maxCount) {
            return $points;
        }

        $filtered = [];
        $currentThreshold = $threshold;

        for ($iterations = 0; $iterations < 3; $iterations++) {
            $filtered = [];
            $lastKept = null;

            foreach ($points as $p) {
                if ($lastKept === null) {
                    $filtered[] = $p;
                    $lastKept = $p;
                } else {
                    $dist = self::haversine(
                        (float) $lastKept['lat'], (float) $lastKept['lon'],
                        (float) $p['lat'], (float) $p['lon']
                    );
                    if ($dist >= $currentThreshold) {
                        $filtered[] = $p;
                        $lastKept = $p;
                    }
                }
            }

            if (count($filtered) <= $maxCount) {
                break;
            }
            $currentThreshold *= 1.5;
        }

        // Final safeguard: if still too many, uniformly subsample
        if (count($filtered) > $maxCount) {
            $step = (int) ceil(count($filtered) / $maxCount);
            $filtered = array_values(array_filter(
                $filtered,
                fn($i) => $i % $step === 0,
                ARRAY_FILTER_USE_KEY
            ));
        }

        return $filtered;
    }
}
