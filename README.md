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

Include the recipe in the project's `deploy.yaml` and name the host `production`:

```yaml
import:
  - vendor/christianhuth/typo3-deployer/recipe/sync.php

hosts:
  production:
    hostname: example.org
    remote_user: deploy
    deploy_path: '~/example.org'
```

`recipe/sync.php` works on top of any Deployer config, e.g. next to Deployer's own `recipe/typo3.php`.
`recipe/deploy.php` already includes it.

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
| `sync_only`                     | `false`                                                | `true` makes `dep deploy` refuse the host            |
| `bin/mysqldump`, `bin/mysql`    | auto-detected (`mysqldump`/`mariadb-dump`, …)          | Remote binaries                                      |

## Deployment

```yaml
# deploy.yaml
import:
  - vendor/christianhuth/typo3-deployer/recipe/deploy.php

config:
  http_user: deploy
  rsync_excludes_extra:
    - some-local-file.sh

hosts:
  production:
    hostname: example.org
    remote_user: deploy
    deploy_path: '~/example.org'
    # Pick a fixed PHP version where the host offers several
    bin/php: /usr/bin/php8.4-cli
```

A `deploy.yaml` can only replace a list, not extend it. Every list setting is therefore overridden as
a whole (copy the default from `recipe/deploy.php` and adjust it), except for the long
`rsync_excludes`: project-specific excludes go into `rsync_excludes_extra` instead.

Around `deploy:symlink` the tasks from `typo3_before_symlink_tasks` / `typo3_after_symlink_tasks` run
in order (database backup, folder structure, permissions, `extension:setup`, reference index, language
packs, cache warmup, page cache flush). Override the lists in the project to add project-specific
tasks, e.g. `typo3:crawler_warmup`. `typo3:fix_folder_structure` runs typo3-console's
`install:fixfolderstructure` by default and is skipped with a warning if the project doesn't have
that command (or the one set in `typo3_fix_folder_structure_command`).

Further settings: `typo3_permission_excludes`, `typo3_executable_files`,
`typo3_fix_folder_structure_command`, `typo3_cache_flush_command`, `rsync_excludes`,
`rsync_excludes_extra`, plus Deployer's `shared_dirs`/`shared_files`/`keep_releases`.

Project-specific tasks that need real logic go into a small PHP file, imported next to the recipe:

```yaml
import:
  - vendor/christianhuth/typo3-deployer/recipe/deploy.php
  - deploy/tasks.php
```

The backup task `typo3:database:export` writes `shared/dbbackup-<timestamp>.sql.gz`, which
`ddev sync --use-existing-db-dump` picks up.
