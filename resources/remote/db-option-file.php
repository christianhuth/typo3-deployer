<?php

declare(strict_types=1);

// Runs on the remote host, piped into php via stdin by src/functions.php - so it never has to be
// deployed there. Reads the Default DB connection of the TYPO3 project in the current working
// directory, writes it as a MySQL option file (for --defaults-file) and prints the database
// name. This way credentials never appear on a command line or in a committed file.
//
// Usage: php -- <option-file> [<webroot>]

$optionFile = $argv[1] ?? '';
$webroot = $argv[2] ?? 'public';
if ($optionFile === '') {
    fwrite(STDERR, "Usage: php -- <option-file> [<webroot>]\n");
    exit(1);
}

// TYPO3 v12+ keeps its configuration in config/system/, older versions in typo3conf/ inside the webroot
$candidates = [
    ['config/system/settings.php', 'config/system/additional.php'],
    [$webroot . '/typo3conf/LocalConfiguration.php', $webroot . '/typo3conf/AdditionalConfiguration.php'],
];
$configuration = null;
foreach ($candidates as [$settingsFile, $additionalFile]) {
    if (is_file($settingsFile)) {
        $configuration = [$settingsFile, $additionalFile];
        break;
    }
}
if ($configuration === null) {
    fwrite(STDERR, 'No TYPO3 configuration found in ' . getcwd() . "\n");
    exit(1);
}

[$settingsFile, $additionalFile] = $configuration;
$GLOBALS['TYPO3_CONF_VARS'] = require $settingsFile;
if (is_file($additionalFile)) {
    // additional.php may rely on parts of TYPO3 that aren't bootstrapped here - the DB connection
    // usually comes from the settings file anyway, so a failing additional.php is only a warning.
    try {
        require $additionalFile;
    } catch (\Throwable $e) {
        fwrite(STDERR, 'Warning: ' . $additionalFile . ' failed (' . $e->getMessage() . "), using " . $settingsFile . " only\n");
    }
}

$db = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] ?? null;
if (!is_array($db) || ($db['dbname'] ?? '') === '') {
    fwrite(STDERR, "No DB/Connections/Default configured\n");
    exit(1);
}
if (!in_array($db['driver'] ?? 'mysqli', ['mysqli', 'pdo_mysql'], true)) {
    fwrite(STDERR, 'Only MySQL/MariaDB is supported, found driver ' . $db['driver'] . "\n");
    exit(1);
}

// Option file values are double-quoted, so backslashes and quotes in passwords need escaping
$lines = ['[client]'];
foreach (['host' => 'host', 'port' => 'port', 'user' => 'user', 'password' => 'password', 'unix_socket' => 'socket'] as $typo3Key => $mysqlKey) {
    if (isset($db[$typo3Key]) && (string)$db[$typo3Key] !== '') {
        $lines[] = $mysqlKey . '="' . addcslashes((string)$db[$typo3Key], '\\"') . '"';
    }
}

umask(0077);
if (file_put_contents($optionFile, implode("\n", $lines) . "\n") === false) {
    fwrite(STDERR, 'Could not write ' . $optionFile . "\n");
    exit(1);
}
chmod($optionFile, 0600);

// mysqldump has no "database" key in its [client] option-file namespace - it prefix-matches unknown
// keys against its own long options, so "database" silently becomes "--databases" (multi-DB dump
// mode). The database name has to be passed as a positional argument instead.
echo $db['dbname'];
