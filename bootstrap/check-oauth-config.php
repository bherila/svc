<?php

use Dotenv\Parser\Parser;

// Parse only the incoming deployment configuration, without loading the application,
// exporting environment variables, or reporting configuration contents/errors.
if (count($argv) !== 3) {
    exit(2);
}

$status = 1;
ob_start();
try {
    require $argv[1];
    $contents = file_get_contents($argv[2], false, null, 0, 1048577);
    if (is_string($contents) && strlen($contents) <= 1048576) {
        $enabled = null;
        $seen = 0;
        foreach ((new Parser)->parse($contents) as $entry) {
            if ($entry->getName() === 'AGENT_API_OAUTH_SERVER_ENABLED') {
                $seen++;
                $value = $entry->getValue()->getOrElse(null);
                if ($value !== null && $value->getVars() === []) {
                    $enabled = $value->getChars();
                }
            }
        }
        // Do not guess at omitted, duplicate or interpolated flags. In particular,
        // incomplete multiline entries can disappear from the parsed entries.
        $status = $seen === 1 && is_string($enabled) && in_array(strtolower($enabled), ['true', '(true)', '1'], true) ? 0 : 1;
    }
} catch (Throwable) {
    // Dotenv errors can contain private configuration values. Never relay them.
}
ob_end_clean();
exit($status);
