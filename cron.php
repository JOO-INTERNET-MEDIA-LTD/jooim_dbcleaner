<?php
/**
 * CLI cron entrypoint for JooIM DB Cleaner.
 * Usage: php modules/jooim_dbcleaner/cron.php --token=TOKEN
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__FILE__).'/../../config/config.inc.php';
require_once dirname(__FILE__).'/../../init.php';

$options = getopt('', array('token:'));
$token = isset($options['token']) ? (string) $options['token'] : '';
$module = Module::getInstanceByName('jooim_dbcleaner');
if (!$module || !$module->active) {
    fwrite(STDERR, "Module is not installed or active\n");
    exit(1);
}

if (!$module->isValidCronToken($token)) {
    fwrite(STDERR, "Invalid token\n");
    exit(1);
}

require_once dirname(__FILE__).'/classes/JooimDbcleanerCleaner.php';
$cleaner = new JooimDbcleanerCleaner($module);
$result = $cleaner->run('cli');

echo json_encode($result, JSON_PRETTY_PRINT).PHP_EOL;
exit($result['status'] === 'error' ? 1 : 0);
