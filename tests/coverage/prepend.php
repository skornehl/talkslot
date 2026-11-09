<?php
/**
 * Line coverage of one PHP process (expert review P1-14), for CI and development only: the Docker image built
 * with COVERAGE=1 (dev/Dockerfile) loads PCOV and runs this file before every request and CLI script
 * (auto_prepend_file). At the end the lines of the app that the process compiled are written to
 * <temp>/talkslot-coverage/<pid>-<time>.json: 1 = executed, -1 = executable but not executed.
 * tests/coverage/report.php merges them.
 */
declare(strict_types=1);

if (function_exists('pcov\start')) {
    \pcov\start();
    register_shutdown_function(static function (): void {
        // Registered from within a shutdown function, so it runs after those of the app (error reports, sessions)
        register_shutdown_function(static function (): void {
            \pcov\stop();
            $lines = \pcov\collect();
            \pcov\clear();
            if ($lines === []) {
                return;
            }
            $dir = sys_get_temp_dir() . '/talkslot-coverage';
            if (!is_dir($dir) && @mkdir($dir, 0777, true)) {
                @chmod($dir, 0777); // web server and CLI (other users) write into the same directory
            }
            @file_put_contents($dir . '/' . getmypid() . '-' . hrtime(true) . '.json', json_encode($lines));
        });
    });
}
