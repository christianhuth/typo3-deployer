<?php

declare(strict_types=1);

namespace Deployer;

use function ChristianHuth\Typo3Deployer\dumpDatabase;

// rsync-based TYPO3 deployment: the project is built (composer install) on the machine running dep and
// rsynced to the host, so the host needs no git or composer. Everything project-specific (hosts,
// http_user, bin/php, extra excludes, additional tasks) is configured in the project's deploy.php.

require_once 'recipe/common.php';
require_once 'contrib/rsync.php';
require_once __DIR__ . '/sync.php';

set('bin/typo3', '{{bin/php}} {{release_path}}/vendor/bin/typo3');

set('keep_releases', 5);

// Updating the reference index takes a long time on bigger projects - 20 instead of 5 minutes
set('default_timeout', 1200);

set('shared_dirs', [
    '{{typo3_webroot}}/fileadmin',
    '{{typo3_webroot}}/typo3temp',
    'var/labels',
]);
set('shared_files', [
    'config/system/settings.php',
]);

// Never rsynced to the host - extend with add('rsync_excludes', [...]) in the project
set('rsync_excludes', [
    // OS specific files
    '.DS_Store',
    'Thumbs.db',
    // Development tooling
    '.ddev',
    '.editorconfig',
    '.fleet',
    '.git*',
    '.github',
    '.gitlab-ci.yml',
    '.idea',
    '.php-cs-fixer.dist.php',
    '.vscode',
    'auth.json',
    'CLAUDE.md',
    'deploy.php',
    '.hosts.yaml',
    'phpstan.neon',
    'phpunit.xml',
    'README*',
    'rector.php',
    'renovate.json',
    'typoscript-lint.yml',
    '/.deployment',
    '/var',
    '/**/Tests/*',
    // Node.js / Playwright files
    'node_modules',
    'package.json',
    'package-lock.json',
    'playwright.config.ts',
    '/tests/',
    '/test-results/',
    '/playwright-report/',
    '/blob-report/',
    '/playwright/.cache/',
    'npm-debug.log*',
]);

set('rsync', fn () => [
    'exclude' => array_merge(get('shared_dirs'), get('shared_files'), get('rsync_excludes')),
    'exclude-file' => false,
    'include' => [],
    'include-file' => false,
    'filter' => [],
    'filter-file' => false,
    'filter-perdir' => false,
    'flags' => 'az',
    'options' => ['delete'],
    'timeout' => 300,
]);
set('rsync_src', './');

task('deploy:update_code', function () {
    invoke('rsync:warmup');
    invoke('rsync');
});

// Paths (relative to the release, shell patterns) whose permissions typo3:correct_permissions leaves
// alone, and files that have to stay executable (e.g. bundled binaries)
set('typo3_permission_excludes', ['vendor/bin*']);
set('typo3_executable_files', []);

// TYPO3 core has no fixfolderstructure command, typo3-console's is install:fixfolderstructure
set('typo3_fix_folder_structure_command', 'install:fixfolderstructure');

// Only page caches - flushing everything after the symlink would throw away typo3:cache_warmup's work
set('typo3_cache_flush_command', 'cache:flush -g pages');

// Run in this order around deploy:symlink - override in the project to add or drop tasks
set('typo3_before_symlink_tasks', [
    // Backup
    'typo3:database:export',
    // Structure
    'typo3:correct_permissions',
    // Extensions and database (extension:setup already applies schema updates)
    'typo3:extension_setup',
    // Data
    'typo3:update_reference_index',
    'typo3:language_update',
    // Caches before the symlink
    'typo3:cache_warmup',
]);
set('typo3_after_symlink_tasks', [
    'typo3:cache_flush',
]);

desc('Make database dump');
task('typo3:database:export', function () {
    // Picked up by "dep sync:db:download --use-existing-db-dump", see sync_existing_db_dumps
    dumpDatabase('{{release_path}}', '{{deploy_path}}/shared/dbbackup-' . date('Ymd-His') . '.sql.gz');
});

desc('Fix folder structure');
task('typo3:fix_folder_structure', function () {
    run('{{bin/typo3}} {{typo3_fix_folder_structure_command}}');
});

desc('Correct file and folder permissions');
task('typo3:correct_permissions', function () {
    $excludes = '';
    foreach (get('typo3_permission_excludes') as $exclude) {
        $excludes .= ' -not -path "{{release_path}}/' . $exclude . '"';
    }
    run('find {{release_path}} -type d' . $excludes . ' -print0 | xargs -0 chmod 0755');
    run('find {{release_path}} -type f' . $excludes . ' -print0 | xargs -0 chmod 0644');
    foreach (get('typo3_executable_files') as $file) {
        run('chmod 0755 "{{release_path}}/' . $file . '"');
    }
});

desc('Set up all installed extensions');
task('typo3:extension_setup', function () {
    run('{{bin/typo3}} extension:setup');
});

desc('Update reference index');
task('typo3:update_reference_index', function () {
    run('{{bin/typo3}} referenceindex:update');
});

desc('Update language files');
task('typo3:language_update', function () {
    // localize.typo3.org occasionally times out on individual packages; var/labels is a persistent
    // shared dir, so a failure here shouldn't block the whole deploy - retry a few times, then
    // continue with whatever was already fetched.
    $maxAttempts = 3;
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            run('{{bin/typo3}} language:update');
            return;
        } catch (\Throwable $e) {
            if ($attempt === $maxAttempts) {
                warning('Language pack update failed after ' . $maxAttempts . ' attempts, continuing deploy with existing packs.');
                return;
            }
            warning('Language pack update failed (attempt ' . $attempt . '/' . $maxAttempts . '), retrying in 5s...');
            sleep(5);
        }
    }
});

desc('Warm up caches');
task('typo3:cache_warmup', function () {
    run('{{bin/typo3}} cache:warmup');
});

desc('Flush caches');
task('typo3:cache_flush', function () {
    run('{{bin/typo3}} {{typo3_cache_flush_command}}');
});

// Actually populates the page cache (cache:warmup only warms the "system" cache group). Needs
// EXT:crawler; the empty "conf" picks up every tx_crawler configuration, "--mode exec" crawls right
// away instead of queuing for the scheduler. Not in the default task list.
desc('Warm up page cache via crawler');
task('typo3:crawler_warmup', function () {
    run('{{bin/typo3}} crawler:buildQueue 1 "" --depth 99 --mode exec');
});

desc('Execute upgrade wizards');
task('typo3:upgrade_all', function () {
    run('{{bin/typo3}} upgrade:run');
});

before('deploy:symlink', function () {
    foreach (get('typo3_before_symlink_tasks') as $task) {
        invoke($task);
    }
});

after('deploy:symlink', function () {
    foreach (get('typo3_after_symlink_tasks') as $task) {
        invoke($task);
    }
});

desc('Deploy TYPO3 project');
task('deploy', [
    'deploy:prepare',
    'deploy:publish',
]);

after('deploy:failed', 'deploy:unlock');
