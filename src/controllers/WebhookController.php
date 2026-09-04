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

        // 2. Look up device by TID + token
        $device = Database::queryOne(
            'SELECT id, user_id, tid, name, webhook_token, config_json, last_config_check_day, config_fix_pending FROM devices WHERE tid = ? AND webhook_token = ?',
            [$tid, $token]
        );

        if (!$device) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Unknown tracker ID or token']);
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
            case 'dump':
                // Device responded to a dump command — config arrives nested under "configuration"
                self::storeConfiguration($device, $data['configuration'] ?? []);
                http_response_code(200);
                header('Content-Type: application/json');
                echo json_encode([]);
                exit;
            case 'configuration':
                // Config sent directly (import/export path)
                self::storeConfiguration($device, $data);
                http_response_code(200);
                header('Content-Type: application/json');
                echo json_encode([]);
                exit;
            default:
                // transition, waypoint, lwt, cmd, etc.
                self::storeEvent($device['id'], $type, $data);
                break;
        }

        // 6. Fetch friend locations (other devices owned by same user)
        $friends = self::getFriendLocations($device['user_id'], $device['id']);

        // 6b. Daily config check + auto-heal:
        //     first location POST of the day requests a config dump to verify;
        //     if drift was detected (from that dump), the next POST pushes the
        //     stored config back via setConfiguration.
        $configCmd = self::maybeConfigCommand($device);
        if ($configCmd !== null) {
            $friends[] = $configCmd;
        }

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
                (device_id, lat, lon, tst, acc, alt, vac, vel, batt, bs, conn, t, tag, poi, poi_imagename, raw_data)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
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
                $data['poi'] ?? null,
                $data['imagename'] ?? null,
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

    // ── Store configuration dump + validate against reference ─────────────
    private static function storeConfiguration(array $device, array $data): void
    {
        // Redact sensitive fields before persisting
        $sanitized = $data;
        foreach (['password', 'encryptionKey', 'username'] as $k) {
            if (isset($sanitized[$k]) && $sanitized[$k] !== '') {
                $sanitized[$k] = '[REDACTED]';
            }
        }

        // Redact the webhook token embedded in the url field (device auth secret)
        if (isset($sanitized['url']) && is_string($sanitized['url'])) {
            $sanitized['url'] = preg_replace('/([?&]token=)[^&]*/i', '$1[REDACTED]', $sanitized['url']);
        }

        $tst = (int)($data['tst'] ?? time());

        // Persist the (sanitized) dump in events_log for audit
        Database::insert(
            'INSERT INTO events_log (device_id, event_type, tst, raw_data) VALUES (?, ?, ?, ?)',
            [$device['id'], 'configuration', $tst, json_encode($sanitized)]
        );

        // Validate against reference config (skip if none)
        $drift = [];
        $reference = null;
        $referenceJson = $device['config_json'] ?? null;
        if ($referenceJson && trim((string)$referenceJson) !== '') {
            $reference = json_decode($referenceJson, true);
            if (is_array($reference)) {
                $drift = self::compareConfig($reference, $data);
            }
        }

        self::recordCheck($device['id'], $tst, empty($drift) ? 0 : 1, empty($drift) ? null : json_encode($drift));

        // If remote config is enabled, flag the device for an auto-heal
        // setConfiguration on its next location POST (or clear the flag when
        // the config is back in sync).
        if (is_array($reference) && !empty($reference['remoteConfiguration'])) {
            Database::execute(
                'UPDATE devices SET config_fix_pending = ? WHERE id = ?',
                [empty($drift) ? 0 : 1, $device['id']]
            );
        }
    }

    /**
     * Daily config check + auto-heal state machine.
     *
     * On the first location POST of the day: request a config dump to verify
     * the device's configuration. If a previous dump detected drift, push the
     * stored reference config back via setConfiguration on the next POST.
     *
     * Only runs when the device has a saved reference config AND
     * remoteConfiguration is enabled (otherwise the app won't accept the
     * setConfiguration command).
     *
     * @return array|null a cmd object to include in the response, or null
     */
    private static function maybeConfigCommand(array $device): ?array
    {
        $configJson = $device['config_json'] ?? null;
        if (!$configJson || trim((string)$configJson) === '') {
            return null;
        }
        $config = json_decode($configJson, true);
        if (!is_array($config) || empty($config['remoteConfiguration'])) {
            return null;
        }

        $today = date('Y-m-d');
        $lastCheckDay = $device['last_config_check_day'] ?? null;

        // First webhook of the day — verify the device configuration
        if ($lastCheckDay !== $today) {
            Database::execute(
                'UPDATE devices SET last_config_check_day = ?, config_fix_pending = 0 WHERE id = ?',
                [$today, $device['id']]
            );
            return ['_type' => 'cmd', 'action' => 'dump'];
        }

        // Drift was detected earlier today — push the stored config to fix it
        if ((int)($device['config_fix_pending'] ?? 0) === 1) {
            Database::execute(
                'UPDATE devices SET config_fix_pending = 0 WHERE id = ?',
                [$device['id']]
            );

            // Build a valid _type: configuration object to send back
            $fix = $config;
            $fix['_type'] = 'configuration';
            $fix['mode'] = 3;
            $fix['cmd'] = true;
            $fix['extendedData'] = true;
            unset($fix['follow']); // custom UI flag, not a real OwnTracks field

            return [
                '_type'        => 'cmd',
                'action'       => 'setConfiguration',
                'configuration' => $fix,
            ];
        }

        return null;
    }

    /** Compare reported config against reference; return list of drifted fields. */
    private static function compareConfig(array $reference, array $reported): array
    {
        $drift = [];

        // System-fixed expectations (HTTP mode frontend)
        $reference['mode'] = 3;          // HTTP
        $reference['cmd'] = true;        // required for remote commands / dump
        $reference['extendedData'] = true;

        $intFields = [
            'monitoring', 'positions', 'adapt', 'locatorInterval',
            'locatorDisplacement', 'downgrade', 'ignoreInaccurateLocations',
            'days', 'maxHistory', 'mode',
        ];
        $boolFields = ['ranging', 'locked', 'allowRemoteLocation', 'cmd', 'extendedData'];

        // Fields whose absence in the reported config is itself a drift
        $critical = ['monitoring', 'mode', 'cmd', 'extendedData'];

        foreach ($intFields as $f) {
            if (!array_key_exists($f, $reference)) continue;
            if (!array_key_exists($f, $reported)) {
                if (in_array($f, $critical, true)) {
                    $drift[] = ['field' => $f, 'expected' => (int)$reference[$f], 'actual' => null];
                }
                continue;
            }
            $expected = (int)$reference[$f];
            $actual = (int)$reported[$f];
            if ($actual !== $expected) {
                $drift[] = ['field' => $f, 'expected' => $expected, 'actual' => $actual];
            }
        }

        foreach ($boolFields as $f) {
            if (!array_key_exists($f, $reference)) continue;
            if (!array_key_exists($f, $reported)) {
                if (in_array($f, $critical, true)) {
                    $drift[] = ['field' => $f, 'expected' => self::toBool($reference[$f]), 'actual' => null];
                }
                continue;
            }
            $expected = self::toBool($reference[$f]);
            $actual = self::toBool($reported[$f]);
            if ($actual !== $expected) {
                $drift[] = ['field' => $f, 'expected' => $expected, 'actual' => $actual];
            }
        }

        // +follow region: only validated when the reference explicitly enables it
        if (array_key_exists('follow', $reference) && self::toBool($reference['follow'])) {
            $hasFollow = false;
            $wps = $reported['waypoints'] ?? null;
            if (is_array($wps)) {
                foreach ($wps as $wp) {
                    if (is_array($wp) && (($wp['desc'] ?? null) === '+follow')) {
                        $hasFollow = true;
                        break;
                    }
                }
            }
            if (!$hasFollow) {
                $drift[] = ['field' => '+follow', 'expected' => 'present', 'actual' => 'missing'];
            }
        }

        return $drift;
    }

    private static function recordCheck(int $deviceId, int $tst, int $hasDrift, ?string $driftFields): void
    {
        Database::insert(
            'INSERT INTO config_checks (device_id, checked_at, has_drift, drift_fields) VALUES (?, ?, ?, ?)',
            [$deviceId, $tst, $hasDrift, $driftFields]
        );
    }

    private static function toBool($value): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value) || is_float($value)) return $value != 0;
        if (is_string($value)) return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
        return (bool)$value;
    }

    // ── Friend locations (other devices of same user + shared devices) ───
    /**
     * Return latest location for each other device owned by the same user
     * PLUS devices that have been shared with this user.
     * OwnTracks app displays these as "friends" on its map.
     *
     * Response format: JSON array of _type objects (OwnTracks HTTP mode spec)
     */
    private static function getFriendLocations(int $userId, int $excludeDeviceId): array
    {
        // Sibling devices (same user)
        $rows = Database::query(
            'SELECT d.id, d.tid, d.name, l.raw_data
             FROM devices d
             LEFT JOIN locations l ON l.id = (
                 SELECT l2.id FROM locations l2
                 WHERE l2.device_id = d.id
                 ORDER BY l2.tst DESC LIMIT 1
             )
             WHERE d.user_id = ? AND d.id != ?',
            [$userId, $excludeDeviceId]
        );

        // Shared devices (devices shared with this user)
        $sharedRows = Database::query(
            'SELECT d.id, d.tid, d.name, ds.custom_name, l.raw_data
             FROM device_shares ds
             JOIN devices d ON ds.device_id = d.id
             LEFT JOIN locations l ON l.id = (
                 SELECT l2.id FROM locations l2
                 WHERE l2.device_id = d.id
                 ORDER BY l2.tst DESC LIMIT 1
             )
             WHERE ds.shared_with_user_id = ?',
            [$userId]
        );

        $friends = [];
        $seenIds = [];
        foreach (array_merge($rows, $sharedRows) as $row) {
            if (isset($seenIds[$row['id']])) continue;
            $seenIds[$row['id']] = true;
            if ($row['raw_data']) {
                $location = json_decode($row['raw_data'], true);
                if ($location && isset($location['_type'])) {
                    // Use custom_name if set, otherwise device name
                    $displayName = $row['custom_name'] ?? $row['name'];
                    $location['tid'] = $displayName;
                    $friends[] = $location;

                    $friends[] = [
                        '_type' => 'card',
                        'tid'   => $displayName,
                        'name'  => $displayName,
                    ];
                }
            }
        }
        return $friends;
    }
}
