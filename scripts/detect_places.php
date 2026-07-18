<?php
/**
 * detect_places.php — CLI script to run place detection in background.
 * 
 * Usage: php detect_places.php <user_id>
 * Writes progress to a temp file so the web UI can check status.
 * Logs to stdout/stderr which are captured to a log file.
 */

declare(strict_types=1);

// Suppress session warnings in CLI (database.php calls session_start())
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

function _log(string $msg): void {
    $ts = date('Y-m-d H:i:s');
    fwrite(STDOUT, "[{$ts}] {$msg}\n");
}

// Bootstrap — use absolute paths
$appDir = dirname(__DIR__);
require_once $appDir . '/src/config/database.php';
require_once $appDir . '/src/lib/PlaceDetector.php';

_log("Started at " . date('Y-m-d H:i:s'));
_log("PHP binary: " . PHP_BINARY);
_log("App dir: " . $appDir);
_log("DB type: " . (getenv('DB_TYPE') ?: 'sqlite'));

$userId = (int) ($argv[1] ?? 0);
$logFile = $argv[2] ?? null;
$mode = $argv[3] ?? 'incremental'; // 'incremental' or 'redetect'

if (!$userId) {
    _log('ERROR: Missing user_id argument');
    fwrite(STDERR, "Usage: php detect_places.php <user_id> [log_file] [mode]\n");
    exit(1);
}

_log("User ID: {$userId}");

$progressFile = sys_get_temp_dir() . '/places_detect_' . $userId . '.json';
_log("Progress file: {$progressFile}");

// Write initial status
file_put_contents($progressFile, json_encode([
    'status' => 'running',
    'started_at' => time(),
    'message' => 'Starting detection...',
    'places_found' => 0,
]));

try {
    // Verify DB connection
    $pdo = Database::raw();
    _log('DB connection OK');

    // Count locations for this user
    $count = Database::queryOne(
        'SELECT COUNT(*) as cnt FROM locations l JOIN devices d ON l.device_id = d.id WHERE d.user_id = ?',
        [$userId]
    );
    $totalPoints = (int) ($count['cnt'] ?? 0);
    _log("Total locations for user: {$totalPoints}");

    if ($totalPoints < 5) {
        _log("Not enough points, skipping detection");
        file_put_contents($progressFile, json_encode([
            'status' => 'done',
            'started_at' => time(),
            'finished_at' => time(),
            'message' => 'Not enough location data to detect places',
            'places_found' => 0,
        ]));
        exit(0);
    }

    // Get devices for this user
    $devices = Database::query('SELECT id, name FROM devices WHERE user_id = ? ORDER BY id ASC', [$userId]);
    _log("Found " . count($devices) . " devices for user");

    foreach ($devices as $di => $device) {
        $deviceId = (int) $device['id'];
        _log("Device [{$di}/" . count($devices) . "]: {$device['name']} (id={$deviceId})");

        // Check last analyzed for this device
        $meta = Database::queryOne('SELECT last_analyzed_at FROM places_meta WHERE user_id = ? AND device_id = ?', [$userId, $deviceId]);
        $lastAnalyzed = $meta ? (int) $meta['last_analyzed_at'] : 0;
        _log("  Last analyzed: {$lastAnalyzed} (" . date('Y-m-d H:i:s', $lastAnalyzed) . ")");

        // Count points for this device
        $dCount = Database::queryOne('SELECT COUNT(*) as cnt FROM locations WHERE device_id = ? AND lat IS NOT NULL AND lon IS NOT NULL', [$deviceId]);
        $dPoints = (int) ($dCount['cnt'] ?? 0);
        _log("  Points for device: {$dPoints}");

        if ($dPoints < 5) {
            _log("  Not enough points, skipping");
            continue;
        }
    }

    // If redetect mode, reset all meta to force full reprocessing
    if ($mode === 'redetect') {
        Database::execute('UPDATE places_meta SET last_analyzed_at = 0 WHERE user_id = ?', [$userId]);
        $deleted = Database::execute("DELETE FROM places WHERE user_id = ? AND (name IS NULL OR name = '')", [$userId]);
        _log("Re-detect: cleared {$deleted} unnamed places, reset last_analyzed_at");
    }

    _log("Processing points from earliest analysis date");

    // Write progress: fetching points
    file_put_contents($progressFile, json_encode([
        'status' => 'running',
        'started_at' => time(),
        'message' => 'Processing ' . number_format($totalPoints) . ' locations (first run, this may take a while)...',
        'places_found' => 0,
        'total_points' => $totalPoints,
    ]));

    // Set up progress callback
    $progressFile = $progressFile; // capture for closure
    PlaceDetector::setProgressCallback(function ($processed, $total, $clusters) use ($progressFile, $totalPoints) {
        $pct = $total > 0 ? round($processed / $total * 100) : 0;
        $msg = "Processing: {$processed}/{$total} points ({$pct}%) — {$clusters} clusters found";
        fwrite(STDOUT, "\r[" . date("Y-m-d H:i:s") . "] {$msg}" . str_repeat(" ", 10));

        // Update progress file (throttled — only update message)
        file_put_contents($progressFile, json_encode([
            'status' => 'running',
            'started_at' => time(),
            'message' => $pct . '% processed — ' . $clusters . ' clusters found',
            'places_found' => $clusters,
            'progress' => $pct,
        ]));
    });

    // Run detection
    if ($mode === 'redetect') {
        _log("Re-detect mode: processing all historical data (" . number_format($totalPoints) . " total points)");
    } else {
        _log("Incremental mode: processing only new points since last analysis");
    }
    $startTime = microtime(true);
    $places = PlaceDetector::detect($userId);
    $elapsed = round(microtime(true) - $startTime, 2);
    fwrite(STDOUT, "\n[" . date("Y-m-d H:i:s") . "] DBSCAN finished in {$elapsed}s\n");
    _log("Detection complete: " . count($places) . " places found");

    // Write final status
    file_put_contents($progressFile, json_encode([
        'status' => 'done',
        'started_at' => time(),
        'finished_at' => time(),
        'message' => 'Detection complete',
        'places_found' => count($places),
    ]));

    _log("Done at " . date('Y-m-d H:i:s'));
    exit(0);
} catch (\Throwable $e) {
    _log("ERROR: " . $e->getMessage());
    _log("Trace: " . $e->getTraceAsString());
    file_put_contents($progressFile, json_encode([
        'status' => 'error',
        'started_at' => time(),
        'finished_at' => time(),
        'message' => $e->getMessage(),
        'places_found' => 0,
    ]));
    exit(1);
}