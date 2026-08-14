<?php
/**
 * Cron job: mark devices for a one-time configuration dump request.
 *
 * Devices that have a saved reference config (config_json) get dump_pending = 1,
 * so the next location POST from the OwnTracks app triggers a cmd dump in the
 * webhook response. The app replies with its _type: "configuration", which the
 * webhook validates against the reference.
 *
 * Usage: php cron_request_config.php
 * Schedule: daily at 1:00 AM (see crontab)
 */

$appDir = __DIR__ . '/..';
require $appDir . '/src/config/database.php';

$affected = Database::execute(
    "UPDATE devices SET dump_pending = 1 WHERE config_json IS NOT NULL AND config_json != ''"
);

$msg = date('Y-m-d H:i:s') . " | OK | Marked {$affected} device(s) for config dump request\n";
fwrite(STDOUT, $msg);
file_put_contents(sys_get_temp_dir() . '/config_request_cron.log', $msg, FILE_APPEND);

// Keep log under 100 lines
$lines = @file(sys_get_temp_dir() . '/config_request_cron.log');
if ($lines && count($lines) > 100) {
    file_put_contents(sys_get_temp_dir() . '/config_request_cron.log', implode('', array_slice($lines, -50)));
}

exit(0);
