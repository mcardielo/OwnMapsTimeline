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

        // 6. Respond
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'stored' => $type,
            'tst' => $data['tst'] ?? time(),
        ]);
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
}
