<?php

declare(strict_types=1);

namespace ChristianHuth\Typo3Deployer;

use function Deployer\get;
use function Deployer\run;

/**
 * Writes the DB credentials of the TYPO3 project in $typo3Root (on the current host) into a MySQL
 * option file and returns the database name.
 */
function writeDbOptionFile(string $typo3Root, string $optionFile): string
{
    // Piped in via stdin, so the helper doesn't need to exist on the remote host (e.g. when the
    // live site wasn't deployed with this package yet)
    $script = base64_encode((string)file_get_contents(__DIR__ . '/../resources/remote/db-option-file.php'));

    return trim(run(sprintf(
        'cd %s && echo %s | base64 -d | {{bin/php}} -- %s %s',
        $typo3Root,
        $script,
        escapeshellarg($optionFile),
        escapeshellarg((string)get('typo3_webroot'))
    )));
}

/**
 * Dumps the database of the TYPO3 project in $typo3Root (on the current host) gzipped into $target.
 * Tables matching one of the $structureOnlyTables shell patterns (e.g. "cache_*") are dumped without
 * their rows.
 *
 * @param list<string> $structureOnlyTables
 */
function dumpDatabase(string $typo3Root, string $target, array $structureOnlyTables = []): void
{
    $optionFile = trim(run('mktemp'));
    try {
        $rawDbName = writeDbOptionFile($typo3Root, $optionFile);
        $dbName = escapeshellarg($rawDbName);
        $mysqldump = '{{bin/mysqldump}} --defaults-file=' . escapeshellarg($optionFile)
            . ' --single-transaction --quick --no-tablespaces';

        $structureOnly = [];
        if ($structureOnlyTables !== []) {
            $tables = run('{{bin/mysql}} --defaults-file=' . escapeshellarg($optionFile) . " -N -B -e 'SHOW TABLES' {$dbName}");
            foreach (preg_split('/\R/', trim($tables)) ?: [] as $table) {
                foreach ($structureOnlyTables as $pattern) {
                    if ($table !== '' && fnmatch($pattern, $table)) {
                        $structureOnly[] = $table;
                        break;
                    }
                }
            }
        }

        $command = $mysqldump . ' ' . $dbName;
        foreach ($structureOnly as $table) {
            $command .= ' --ignore-table=' . escapeshellarg($rawDbName . '.' . $table);
        }
        if ($structureOnly !== []) {
            $command = "{ {$command} && {$mysqldump} --no-data {$dbName} " . implode(' ', array_map('escapeshellarg', $structureOnly)) . '; }';
        }
        run("set -o pipefail; {$command} | gzip > {$target}");
    } finally {
        run('rm -f ' . escapeshellarg($optionFile));
    }
}

/**
 * Path of the first of the given binaries found on the current host.
 */
function locateBinary(string ...$names): string
{
    foreach ($names as $name) {
        $path = trim(run('command -v ' . escapeshellarg($name) . ' || true'));
        if ($path !== '') {
            return $path;
        }
    }
    throw new \RuntimeException('None of ' . implode(', ', $names) . ' found on ' . \Deployer\currentHost()->getAlias());
}
