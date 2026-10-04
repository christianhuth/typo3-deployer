# typo3-deployer

[Deployer](https://deployer.org) recipes for TYPO3 projects:

- **`recipe/sync.php`** – downloads database and fileadmin from a Deployer host, e.g. into DDEV via
  `ddev sync`. Works on top of any Deployer config, including Deployer's own `recipe/typo3.php`.
- **`recipe/deploy.php`** – rsync-based deployment: the project is built where `dep` runs (CI) and
  rsynced to the host, so the host needs neither git nor composer. Includes `recipe/sync.php`.

## Installation

Not on Packagist yet, so add the repository to the project's `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/christianhuth/typo3-deployer" }
]
```

```bash
ddev composer require christianhuth/typo3-deployer:dev-main
ddev composer require --dev deployer/deployer:^7.5
```

It is a regular (not a dev) dependency on purpose: CI usually runs `composer install --no-dev` before
`dep deploy`, and the recipe has to be there at that point.

## Sync into DDEV

Copy the DDEV integration into the project once:

```bash
cp vendor/christianhuth/typo3-deployer/resources/ddev/providers/production.yaml .ddev/providers/
cp vendor/christianhuth/typo3-deployer/resources/ddev/commands/host/sync .ddev/commands/host/
```

Include the recipe in the project's Deployer config and name the host `production`:

```php
// deploy.php
namespace Deployer;

require 'vendor/christianhuth/typo3-deployer/recipe/sync.php';

host('production')
    ->set('hostname', 'example.org')
    ->set('remote_user', 'deploy')
    ->set('deploy_path', '~/example.org');
```

```yaml
# deploy.yaml
import:
  - vendor/christianhuth/typo3-deployer/recipe/sync.php
hosts:
  production:
    hostname: example.org
    remote_user: deploy
    deploy_path: '~/example.org'
```

Then:

```bash
ddev auth ssh                       # once per DDEV session/reboot
ddev sync                           # fresh DB dump + fileadmin, imported by DDEV
ddev sync --use-existing-db-dump    # newest dump of the last deploy instead of a fresh one
ddev sync --skip-files              # also: --skip-db, --skip-import, --host=<deployer-host>, -y
```

`ddev sync` is a thin wrapper around `ddev pull production`, whose provider runs
`dep sync:db:download` / `dep sync:files:download` inside the web container. Both tasks can also be
used without DDEV, they write `db.sql.gz` and `files/` into `sync_local_dir`.

A fresh dump is created in a temporary file on the host (removed afterwards). The DB credentials are
read from the TYPO3 configuration on the host (`config/system/settings.php` + `additional.php`, or
`typo3conf/LocalConfiguration.php` for older versions) and only ever end up in a temporary `0600`
MySQL option file – never on a command line.

### Settings

| Setting                         | Default                                                | Purpose                                              |
|---------------------------------|--------------------------------------------------------|------------------------------------------------------|
| `sync_typo3_root`               | `{{current_path}}`                                     | Remote TYPO3 project root the credentials come from  |
| `sync_fileadmin_path`           | `{{deploy_path}}/shared/{{typo3_webroot}}/fileadmin`   | Remote fileadmin                                     |
| `sync_existing_db_dumps`        | `{{deploy_path}}/shared/dbbackup-*.sql*`               | Glob for `--use-existing-db-dump` (newest wins)      |
| `sync_db_structure_only_tables` | `cache_*`, `cf_*`, `be_sessions`, `fe_sessions`        | Dumped without rows                                  |
| `sync_files_exclude`            | `_processed_/`, `_temp_/`                              | Not downloaded, TYPO3 regenerates them               |
| `sync_local_dir`                | `.ddev/.downloads`                                     | Local target                                         |
| `bin/mysqldump`, `bin/mysql`    | auto-detected (`mysqldump`/`mariadb-dump`, …)          | Remote binaries                                      |

## Deployment

```php
// deploy.php
namespace Deployer;

require 'vendor/christianhuth/typo3-deployer/recipe/deploy.php';

import('.hosts.yaml');

set('http_user', 'deploy');
set('bin/php', '/usr/bin/php8.4-cli');
add('rsync_excludes', ['some-local-file.sh']);
```

Around `deploy:symlink` the tasks from `typo3_before_symlink_tasks` / `typo3_after_symlink_tasks` run
in order (database backup, permissions, `extension:setup`, reference index, language packs, cache
warmup, page cache flush). Override the lists in the project to add project-specific tasks, e.g.
`typo3:crawler_warmup` or `typo3:fix_folder_structure`.

Further settings: `typo3_permission_excludes`, `typo3_executable_files`,
`typo3_fix_folder_structure_command`, `typo3_cache_flush_command`, `rsync_excludes`, plus Deployer's
`shared_dirs`/`shared_files`/`keep_releases`.

The backup task `typo3:database:export` writes `shared/dbbackup-<timestamp>.sql.gz`, which
`ddev sync --use-existing-db-dump` picks up.
