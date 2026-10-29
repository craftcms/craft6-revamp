# Craft 6 Upgrade Tool

This CLI tool automates a number of steps in the Craft 6 upgrade process.

> [!TIP]
> Craft 6 is in alpha. Follow the [Upgrading to Craft 6.x](https://craftcms.com/docs/6.x/upgrade.html) guide for complete upgrade instructions: it covers preparing your project, running this tool, and what to check afterwards. See [Planning for the Laravel Transition](https://craftcms.com/knowledge-base/laravel-transition-planning) for the release timeline and plugin compatibility.

> [!CAUTION]
> Make sure you have a way to restore your project, if you encounter issues.
> _Use this tool at your own risk_, and _do not run this on your live server_!
> It is only intended to upgrade projects in a development environment.

## Before you start

The tool changes files, not your database or your servers. Before running it:

- Update the project to the latest Craft 5 release (5.9.0 or later is required), and plugins to their latest versions;
- Resolve outstanding deprecation warnings;
- If you want to control how category groups, tag groups, and global sets become entries, run the `php craft entrify/*` commands on Craft 5 now;
- Let the queue finish: the queue table is rebuilt during the upgrade, and pending jobs are lost;
- Back up your database, and commit (or stash) your code changes, so you can review and undo what the tool changes.

## What it does

Each action is printed to the console, followed by a confirmation, a warning, or “Nothing to do” when it doesn’t apply to your project.

### Checks, before anything changes

You will be prompted before the tool takes potentially destructive actions, and can cancel at any of these points:

- Verifies the project has `composer.json` and `composer.lock` files, and runs Craft 5.9.0 or later;
- Asks before continuing if the project has uncommitted Git changes;
- Asks before continuing if `bootstrap/` or `config/craft/` already exist, which means the tool has probably run before. Steps that already happened are skipped, and files they created aren’t updated;
- Lists the packages in `composer.lock` that require `craftcms/cms` (plugins, and packages like `craftcms/generator`), and whether Packagist has a version of each that supports Craft 6. This is only a report; it’s skipped when Packagist can’t be reached. Filesystem, mail transport, and logging plugins are flagged as probably no longer needed, since Laravel disks, mailers, and logging channels replace them;
- Warns about category groups, tag groups, and global sets, and prints the `php craft entrify/*` commands to convert them to entries on Craft 5;
- Finds the web root, and asks whether to rename it to `public/`;
- Shows the lines of a customized `index.php` or `bootstrap.php` that will be lost, then asks to proceed.

### Changes

- **`composer.json`:** requires `craftcms/cms` `^6.0.0-alpha.1` and [craftcms/yii2-adapter](https://github.com/craftcms/yii2-adapter), updates `craftcms/generator` to `3.x-dev` if it’s installed, removes [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv), `yiisoft/yii2-shell` (use `artisan tinker` instead), and the `config.platform.php` override, and adds Laravel’s `post-autoload-dump` scripts;
- **DDEV:** switches the project type to `laravel`, sets PHP 8.5, and updates the docroot when the web root is renamed;
- **Environment variables:** in `.env`, `.ddev/.env`, `.ddev/.env.web`, and the `.env.example*` files, renames the `CRAFT_DB_*` variables to Laravel’s `DB_*` ones (including `CRAFT_DB_TABLE` and `CRAFT_DB_TABLE_PREFIX` to `DB_TABLE_PREFIX`), `CRAFT_DEV_MODE` to `APP_DEBUG`, and removes `CRAFT_SECURITY_KEY`. Sets `APP_ENV` from `CRAFT_ENVIRONMENT` (`dev` becomes `local`) and `APP_URL` from `PRIMARY_SITE_URL`;
- **OAuth keys:** moves `oauth-private.key` and `oauth-public.key` into `storage/` if `CRAFT_STORAGE_PATH` pointed somewhere else, so Laravel Passport keeps accepting tokens issued by Craft 5;
- **Laravel files:** creates the `artisan` CLI entrypoint, `bootstrap/app.php`, the `storage/framework/` folders, and Laravel’s default config files in `config/` (existing files are kept);
- **Craft config:** moves Craft’s config files into `config/craft/`. In `general.php` (and `general.web.php`/`general.console.php`), rewrites paths based on `dirname(__DIR__)` or `CRAFT_BASE_PATH` to `base_path()` or `public_path()`, and removes the `omitScriptNameInUrls`, `pathParam`, and `enableTwigSandbox` settings;
- **Project layout:** renames `translations/` to [`lang/`](https://laravel.com/docs/13.x/localization), moves `templates/` to `resources/views/` (asking first if it already exists), and renames the web root to `public/`;
- **Entrypoints:** replaces `index.php`, and removes `bootstrap.php` and the `craft` CLI entrypoint (which is re-created the first time you publish vendor assets);
- **`.gitignore`:** adds `/public/vendor/craft`, `/public/storage`, and `/storage/*.key`.

### Next steps

Finally, the tool lists the follow-up actions that apply to your project, such as:

- Laravel disks to add to `config/filesystems.php`, based on your Craft filesystems;
- The Laravel mailer that replaces your email transport;
- Settings and paths the tool couldn’t update safely;
- General config settings that moved into Laravel config (`timezone`, `defaultCookieDomain`, `blowfishHashCost`, `phpSessionName`, `systemMessageTemplate`, `elevatedSessionDuration`, and `testToEmailAddress`), and where each one goes now;
- References to the renamed `CRAFT_DB_*`, `CRAFT_DEV_MODE`, and `CRAFT_SECURITY_KEY` variables in config files, `.github/`, `docker-compose*.yml`, `Dockerfile*`, `.platform*`, `fly.toml`, and `render.yaml`;
- Multi-environment config arrays, app-type config files, `db.php`, Yii components and modules in `app.php`, URL rules in `routes.php`, and custom HTMLPurifier configs;
- Plugins without a Craft 6 version, and plugins that Laravel configuration replaces;
- `composer update` (which also publishes Craft’s assets and config via `artisan craft:setup:publish`), `artisan key:generate`, and `artisan craft:up` to run Craft 6’s migrations.

## What it doesn’t do

The tool only prepares your local project’s files. You’re responsible for:

- **Your database.** The tool doesn’t touch it, and doesn’t run migrations itself. `artisan craft:up` is the last of its next steps;
- **Plugins.** The tool reports which plugins have a Craft 6 version, but doesn’t change their constraints in `composer.json`, or remove or replace them. During the alpha, you may need to set `CRAFT_DISABLED_PLUGINS="*"` or uninstall incompatible plugins before upgrading;
- **Categories, tags, and global sets.** The tool doesn’t entrify them; run the `php craft entrify/*` commands on Craft 5 first if you want to;
- **Customized entrypoints.** Lines you added to `index.php` or `bootstrap.php` (like PHP constants or custom environment loading) are shown, then removed; you’ll need to find Laravel equivalents;
- **Config it can only flag.** Filesystems, mail, multi-environment configs, app-type configs, `db.php`, Yii components and modules, URL rules, and HTMLPurifier configs are listed as next steps, not converted. Paths in `general.php` the tool doesn’t recognize are left as they are, and the suggested disks keep Craft aliases other than `@webroot`, `@web`, `@root`, and `@storage` for you to replace;
- **Templates and custom code.** Twig templates, modules, and other PHP code aren’t changed. `craftcms/yii2-adapter` keeps them working, and Craft logs deprecation warnings for what to update. Don’t remove the adapter until they’re resolved;
- **Non-standard project layouts.** The tool expects Craft 5’s default `config/`, `templates/`, and `translations/` folders. Custom locations (such as `CRAFT_TEMPLATES_PATH`) aren’t followed;
- **Other environments.** Only local files change. Update `.env` files, OAuth keys (`PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY`), web server document roots, PHP versions outside DDEV, deployment scripts, cron jobs, and queue workers on your servers yourself;
- **Backups.** The tool doesn’t keep a copy of what it changes. Use Git (or another backup) to review and undo its changes.

## Installation

The tool is installed as a [global](https://getcomposer.org/doc/03-cli.md#global) Composer package, on any system running PHP 8.2 or newer.

```sh
composer global require craftcms/craft6-revamp -W
```

DDEV or Docker users: it is safe to do this from any directory on your host machine.
It should _not_ alter your project’s `composer.json`.

If you get a dependency conflict error, try running the following command first:

```sh
composer global update
```

## Usage

To prepare an existing Craft 5 project for Craft 6, run the tool from its root directory:

```sh
craft6-revamp
```

The tool will exit if it can’t find `composer.json` and `composer.lock` files, or if it can’t confidently determine the project is compatible.

If the command doesn’t resolve (say, because your user’s Composer `bin` directory is not in your `$PATH`), you can run the command directly:

```sh
~/.composer/vendor/bin/craft6-revamp
```

You may also pass a project path, if you have many projects to update:

```sh
craft6-revamp /path/to/project
```
