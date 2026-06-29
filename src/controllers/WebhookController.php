<?php
/**
 * WebhookController — Ingests location data from OwnTracks app.
 *
 * Endpoint: POST /webhook?tid=TID&token=TOKEN
 * No user auth required — validated by TID + token match in URL.
 *
 * OwnTracks sends JSON body with _type:
 *   "location"    → stored in locations table
 *   "transition"  → stored in events_log
 *   "waypoint"    → stored in events_log
 *   "lwt"         → stored in events_log (online/offline)
 *   "cmd"         → stored in events_log (command response)
 */

declare(strict_types=1);

class WebhookController
{
    // ── Ingest (POST /webhook) ──────────────────────────────────────────────
    public static function ingest(array $query = [], array $body = []): void
    {
        // 1. Validate URL params
        $tid   = $query['tid'] ?? null;
        $token = $query['token'] ?? null;

        if (!$tid || !$token) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Missing tid or token parameter']);
            exit;
        }

        // 2. Look up device by TID
        $device = Database::queryOne(
            'SELECT id, user_id, tid, name, webhook_token FROM devices WHERE tid = ?',
            [$tid]
        );

        if (!$device) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Unknown tracker ID']);
            exit;
        }

        // 3. Validate token
        if (!hash_equals($device['webhook_token'], $token)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid token']);
            exit;
        }

        // 4. Read request body
        $rawInput = file_get_contents('php://input');
        if (empty($rawInput)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Empty body']);
            exit;
        }

        $data = json_decode($rawInput, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
            exit;
        }

        // 5. Route by _type
        $type = $data['_type'] ?? 'location';

        switch ($type) {
            case 'location':
                self::storeLocation($device['id'], $data);
                break;
            default:
                // transition, waypoint, lwt, cmd, etc.
                self::storeEvent($device['id'], $type, $data);
                break;
        }

        // 6. Fetch friend locations (other devices owned by same user)
        $friends = self::getFriendLocations($device['user_id'], $device['id']);

        // 7. Respond — OwnTracks HTTP mode expects a JSON array of _type objects
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($friends);
        exit;
    }

    // ── Store location ──────────────────────────────────────────────────────
    private static function storeLocation(int $deviceId, array $data): void
    {
        Database::insert(
            'INSERT INTO locations
                (device_id, lat, lon, tst, acc, alt, vac, vel, batt, bs, conn, t, tag, raw_data)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $deviceId,
                $data['lat'] ?? null,
                $data['lon'] ?? null,
                (int) ($data['tst'] ?? time()),
                $data['acc'] ?? null,
                $data['alt'] ?? null,
                $data['vac'] ?? null,
                $data['vel'] ?? null,
                $data['batt'] ?? null,
                $data['bs'] ?? null,
                $data['conn'] ?? null,
                $data['t'] ?? null,
                $data['tag'] ?? null,
                json_encode($data),
            ]
        );
    }

    // ── Store event ─────────────────────────────────────────────────────────
    private static function storeEvent(int $deviceId, string $type, array $data): void
    {
        Database::insert(
            'INSERT INTO events_log
                (device_id, event_type, tst, raw_data)
            VALUES
                (?, ?, ?, ?)',
            [
                $deviceId,
                $type,
                (int) ($data['tst'] ?? time()),
                json_encode($data),
            ]
        );
    }

    // ── Friend locations (other devices of same user) ──────────────────────
    /**
     * Return latest location for each other device owned by the same user.
     * OwnTracks app displays these as "friends" on its map.
     *
     * Response format: JSON array of _type objects (OwnTracks HTTP mode spec)
     */
    private static function getFriendLocations(int $userId, int $excludeDeviceId): array
    {
        $devices = Database::query(
            'SELECT id, tid, name FROM devices WHERE user_id = ? AND id != ?',
            [$userId, $excludeDeviceId]
        );

        $friends = [];
        foreach ($devices as $dev) {
            $latest = Database::queryOne(
                'SELECT raw_data FROM locations WHERE device_id = ? ORDER BY tst DESC LIMIT 1',
                [$dev['id']]
            );
            if ($latest && $latest['raw_data']) {
                $location = json_decode($latest['raw_data'], true);
                if ($location && isset($location['_type'])) {
                    // Override tid with the device name from DB
                    $location['tid'] = $dev['name'];
                    $friends[] = $location;

                    // Also include a card so the app shows the full name
                    $friends[] = [
                        '_type' => 'card',
                        'tid'   => $dev['name'],
                        'name'  => $dev['name'],
                    ];
                }
            }
        }
        return $friends;
    }
}
