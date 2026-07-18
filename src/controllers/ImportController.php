<?php
/**
 * ImportController — GPX file import with device selection and timezone adjustment.
 */

declare(strict_types=1);

class ImportController
{
    private const SESSION_KEY = 'gpx_import_data';

    // ── Upload form (GET /import) ──────────────────────────────────────────
    public static function form(array $query = [], array $body = []): void
    {
        $username = $_SESSION['username'];
        $error    = $_SESSION['import_error'] ?? null;
        unset($_SESSION['import_error'], $_SESSION[self::SESSION_KEY]);

        View::render('import', [
            'username'  => $username,
            'error'     => $error,
            'step'      => 'upload',
            'pageTitle' => 'Import GPX',
            'navLinks'  => [
                ['url' => '/dashboard', 'label' => 'Dashboard'],
                ['url' => '/devices', 'label' => 'Devices'],
                ['url' => '/places', 'label' => 'Places'],
            ],
        ], 'layout');
    }

    // ── Parse + preview (POST /import/preview) ─────────────────────────────
    public static function preview(array $query = [], array $body = []): void
    {
        $username = $_SESSION['username'];

        // Validate upload
        if (!isset($_FILES['gpx_file']) || $_FILES['gpx_file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['import_error'] = 'Please select a valid GPX file';
            header('Location: /import', true, 302);
            exit;
        }

        $file = $_FILES['gpx_file'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['gpx', 'xml'])) {
            $_SESSION['import_error'] = 'Only .gpx or .xml files are supported';
            header('Location: /import', true, 302);
            exit;
        }

        $xml = file_get_contents($file['tmp_name']);
        if (!$xml) {
            $_SESSION['import_error'] = 'Could not read file';
            header('Location: /import', true, 302);
            exit;
        }

        // Parse GPX
        libxml_use_internal_errors(true);
        $gpx = simplexml_load_string($xml);
        if (!$gpx) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            $_SESSION['import_error'] = 'Invalid GPX/XML: ' . ($errors[0]->message ?? 'unknown error');
            header('Location: /import', true, 302);
            exit;
        }

        // Register GPX namespace
        $ns = 'http://www.topografix.com/GPX/1/1';
        $gpx->registerXPathNamespace('g', $ns);

        // Extract track points
        $points = [];
        foreach ($gpx->xpath('//g:trkpt') as $pt) {
            $time = (string) ($pt->time ?? '');
            if (!$time) continue; // skip points without timestamp

            $tst = strtotime($time);
            if ($tst === false) continue;

            $points[] = [
                'lat'  => (float) $pt['lat'],
                'lon'  => (float) $pt['lon'],
                'ele'  => (float) ($pt->ele ?? 0),
                'tst'  => $tst,
                'time' => $time,
                'acc'  => (float) ($pt->xpath('g:extensions/g:accuracy')[0] ?? 0) ?: null,
            ];
        }

        // Also extract waypoints
        foreach ($gpx->xpath('//g:wpt') as $pt) {
            $time = (string) ($pt->time ?? '');
            if (!$time) {
                $time = date('c', time());
                $tst  = time();
            } else {
                $tst = strtotime($time);
                if ($tst === false) continue;
            }

            $points[] = [
                'lat'  => (float) $pt['lat'],
                'lon'  => (float) $pt['lon'],
                'ele'  => (float) ($pt->ele ?? 0),
                'tst'  => $tst,
                'time' => $time,
                'acc'  => null,
            ];
        }

        if (empty($points)) {
            $_SESSION['import_error'] = 'No valid track points found in the GPX file';
            header('Location: /import', true, 302);
            exit;
        }

        // Sort by time
        usort($points, fn($a, $b) => $a['tst'] <=> $b['tst']);

        // Store in session for the execute step
        $_SESSION[self::SESSION_KEY] = [
            'points'     => $points,
            'file_name'  => basename($file['name']),
            'total'      => count($points),
            'start_time' => date('Y-m-d H:i:s', $points[0]['tst']),
            'end_time'   => date('Y-m-d H:i:s', end($points)['tst']),
        ];

        // Fetch user's devices
        $devices = self::getUserDevices($username);

        // Auto-detect timezone offset: GPX times are UTC, user's TZ from env
        $userTz   = getenv('TZ') ?: 'UTC';
        $detectedOffset = self::tzOffsetHours($userTz, $points[0]['tst']);

        View::render('import', [
            'username'       => $username,
            'step'           => 'preview',
            'points'         => $points,
            'fileName'       => basename($file['name']),
            'total'          => count($points),
            'startTime'      => date('Y-m-d H:i:s', $points[0]['tst']),
            'endTime'        => date('Y-m-d H:i:s', end($points)['tst']),
            'devices'        => $devices,
            'detectedOffset' => $detectedOffset,
            'pageTitle'      => 'Import GPX',
            'navLinks'  => [
                ['url' => '/dashboard', 'label' => 'Dashboard'],
                ['url' => '/places', 'label' => 'Places'],
                ['url' => '/devices', 'label' => 'Devices'],
            ],
        ], 'layout');
    }

    // ── Execute import (POST /import/execute) ──────────────────────────────
    public static function execute(array $query = [], array $body = []): void
    {
        $data = $_SESSION[self::SESSION_KEY] ?? null;
        if (!$data) {
            $_SESSION['import_error'] = 'No import data found. Please upload again.';
            header('Location: /import', true, 302);
            exit;
        }

        $deviceId = (int) ($_POST['device_id'] ?? 0);
        $offset   = (float) ($_POST['tz_offset'] ?? 0);

        // Validate device ownership
        $device = self::getUserDevice($deviceId);
        if (!$device) {
            $_SESSION['import_error'] = 'Invalid device selected';
            header('Location: /import', true, 302);
            exit;
        }

        $points    = $data['points'];
        $imported  = 0;
        $skipped   = 0;
        $pdo       = Database::raw();

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO locations
                    (device_id, lat, lon, tst, acc, alt, raw_data)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?)'
            );

            foreach ($points as $pt) {
                // Apply timezone offset
                $adjustedTst = (int) ($pt['tst'] + ($offset * 3600));

                $rawData = json_encode([
                    '_type'      => 'imported_gpx',
                    'source'     => 'gpx_import',
                    'original_tst' => $pt['tst'],
                    'original_time' => $pt['time'],
                    'offset_applied' => $offset,
                ]);

                $stmt->execute([
                    $deviceId,
                    $pt['lat'],
                    $pt['lon'],
                    $adjustedTst,
                    $pt['acc'] ?: null,
                    $pt['ele'] ?: null,
                    $rawData,
                ]);
                $imported++;
            }

            $pdo->commit();

            unset($_SESSION[self::SESSION_KEY]);
            $_SESSION['import_success'] = "Imported {$imported} points to device '{$device['name']}'";

            // If offset was applied, note it
            if ($offset != 0) {
                $sign = $offset > 0 ? '+' : '';
                $_SESSION['import_success'] .= " (time adjusted by {$sign}{$offset}h)";
            }

        } catch (\Throwable $e) {
            $pdo->rollBack();
            $_SESSION['import_error'] = 'Import failed: ' . $e->getMessage();
        }

        header('Location: /import', true, 302);
        exit;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────
    private static function getUserDevices(string $username): array
    {
        $authMode = getenv('AUTH_MODE') ?: 'local';
        if ($authMode === 'authelia') {
            $dbUser = Database::queryOne('SELECT id FROM users WHERE username = ?', [$username]);
            return $dbUser ? Database::query('SELECT * FROM devices WHERE user_id = ? ORDER BY name', [$dbUser['id']]) : [];
        }
        return Database::query('SELECT * FROM devices WHERE user_id = ? ORDER BY name', [$_SESSION['user_id']]);
    }

    private static function getUserDevice(int $deviceId): ?array
    {
        $username = $_SESSION['username'];
        $authMode = getenv('AUTH_MODE') ?: 'local';
        if ($authMode === 'authelia') {
            $dbUser = Database::queryOne('SELECT id FROM users WHERE username = ?', [$username]);
            if (!$dbUser) return null;
            return Database::queryOne('SELECT * FROM devices WHERE id = ? AND user_id = ?', [$deviceId, $dbUser['id']]);
        }
        return Database::queryOne('SELECT * FROM devices WHERE id = ? AND user_id = ?', [$deviceId, $_SESSION['user_id']]);
    }

    /** Calculate hours offset between a timezone and UTC for a given timestamp */
    private static function tzOffsetHours(string $tz, int $tst): float
    {
        try {
            $dtUtc   = new DateTime("@{$tst}", new DateTimeZone('UTC'));
            $dtLocal = new DateTime("@{$tst}", new DateTimeZone($tz ?: 'UTC'));
            return $dtLocal->getOffset() / 3600;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
