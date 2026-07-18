<?php
/**
 * Cron job: run incremental place detection for all users.
 * Usage: php cron_detect_places.php
 * Schedule: daily at midnight
 */

// Bootstrap app
$appDir = __DIR__ . '/..';
require $appDir . '/src/config/database.php';

// Get all users that have devices (only those can have places)
$users = Database::query('SELECT DISTINCT d.user_id FROM devices d WHERE EXISTS (SELECT 1 FROM locations l WHERE l.device_id = d.id AND l.lat IS NOT NULL AND l.lon IS NOT NULL)');

if (empty($users)) {
    fwrite(STDOUT, date('Y-m-d H:i:s') . " No users with location data found.\n");
    exit(0);
}

$phpBin = PHP_BINARY;
if (strpos($phpBin, 'php-fpm') !== false) {
    $phpBin = str_replace('php-fpm', 'php', $phpBin);
}
if (!file_exists($phpBin)) {
    foreach (['/usr/local/bin/php', '/usr/bin/php'] as $candidate) {
        if (file_exists($candidate)) {
            $phpBin = $candidate;
            break;
        }
    }
}

$scriptPath = __DIR__ . '/detect_places.php';

foreach ($users as $user) {
    $userId = (int) $user['user_id'];
    $logFile = sys_get_temp_dir() . '/places_detect_' . $userId . '.log';

    // Check if already running
    $progressFile = sys_get_temp_dir() . '/places_detect_' . $userId . '.json';
    if (file_exists($progressFile)) {
        $progress = json_decode(file_get_contents($progressFile), true);
        if ($progress && ($progress['status'] ?? '') === 'running') {
            fwrite(STDOUT, date('Y-m-d H:i:s') . " User {$userId}: detection already running, skipping.\n");
    file_put_contents(sys_get_temp_dir() . '/places_cron.log', date('Y-m-d H:i:s') . " | SKIP | User {$userId} already running\n", FILE_APPEND);
            continue;
        }
    }

    fwrite(STDOUT, date('Y-m-d H:i:s') . " User {$userId}: starting incremental detection...\n");

    $cmd = sprintf(
        '%s %s %d %s incremental > %s 2>&1 &',
        escapeshellarg($phpBin),
        escapeshellarg($scriptPath),
        $userId,
        escapeshellarg($logFile),
        escapeshellarg($logFile)
    );
    exec($cmd);

    // Wait a bit between users to avoid overloading
    sleep(2);
}

fwrite(STDOUT, date('Y-m-d H:i:s') . " Cron detection launched for " . count($users) . " user(s).\n");

// Log to cron log file (readable by web app)
$cronLog = sys_get_temp_dir() . '/places_cron.log';
$entry = date('Y-m-d H:i:s') . " | OK | Launched detection for " . count($users) . " user(s)\n";
file_put_contents($cronLog, $entry, FILE_APPEND);

// Keep log under 100 lines
$lines = file($cronLog);
if ($lines && count($lines) > 100) {
    file_put_contents($cronLog, implode('', array_slice($lines, -50)));
}

exit(0);