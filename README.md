# Craft 6 Upgrade Tool

This CLI tool automates a number of steps in the Craft 6 upgrade process.

> [!TIP]
> Keep an eye on our [Planning for the Laravel Transition](https://craftcms.com/knowledge-base/laravel-transition-planning) article for complete upgrade instructions, as the first Alpha release approaches!

<details open>
<summary><strong>Summary of actions</strong> 🪄</summary>

The following tasks are handled by the script.
A description of each action is output to the console, followed by a confirmation or error message.

You will be prompted before the tool takes potentially destructive actions.

- Verifies you’re running a supported version of Craft 5;
- Asks before continuing if the project has uncommitted Git changes, or if `bootstrap/` or `config/craft/` already exist from an earlier run;
- Lists the packages in `composer.lock` that require `craftcms/cms`, and whether Packagist has a version of each that supports Craft 6 (this is only a report, and is skipped when Packagist can’t be reached);
- Warns about category groups, tag groups, and global sets, which should be converted with `php craft entrify/*` on Craft 5 first;
- Shows the lines of a customized `index.php` or `bootstrap.php` that will be lost;
- Updates Composer dependencies for Craft and the Generator, adds [craftcms/yii2-adapter](https://github.com/craftcms/yii2-adapter), and removes [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv) and `yiisoft/yii2-shell` (use `artisan tinker` instead);
- Switches the DDEV project type to `laravel`, and updates the project’s PHP version;
- Renames a handful of environment variables to match Laravel conventions in `.env` and the `.env.example*` files, and sets `APP_ENV` (from `CRAFT_ENVIRONMENT`, with `dev` becoming `local`) and `APP_URL` (from `PRIMARY_SITE_URL`);
- Moves the OAuth signing keys (`oauth-private.key` and `oauth-public.key`) into `storage/` if `CRAFT_STORAGE_PATH` pointed somewhere else, so Laravel Passport keeps accepting tokens issued by Craft 5;
- Creates the `artisan` CLI entrypoint, Laravel’s `bootstrap/` files, and replaces `index.php`;
- Scaffolds Laravel’s `framework/` directory;
- Moves existing Craft-specific configuration files into `config/craft/`;
- Rewrites paths in `config/craft/general.php` (and `general.web.php`/`general.console.php`) that were based on `dirname(__DIR__)` or `CRAFT_BASE_PATH` to `base_path()` or `public_path()`, and removes the `omitScriptNameInUrls`, `pathParam`, and `enableTwigSandbox` settings;
- Renames the web root to `public/`;
- Removes the legacy `craft` CLI entrypoint (this will get re-created the first time you publish vendor assets from Laravel);
- Renames the `translations/` directory to [`lang/`](https://laravel.com/docs/13.x/localization);
- Removes the legacy `boostrap.php` file;
- Adds `/public/vendor/craft`, `/public/storage`, and `/storage/*.key` to `.gitignore`;

A few manual follow-up actions are then suggested, when relevant to your project: Laravel disks for your Craft filesystems, settings the tool couldn’t update safely, multi-environment and app-type config files, `db.php`, Yii components and modules in `app.php`, URL rules in `routes.php`, custom HTMLPurifier configs, the email transport, and packages without a Craft 6 version.

</details>

> [!CAUTION]
> Make sure you have a way to restore your project, if you encounter issues.
> _Use this tool at your own risk_, and _do not run this on your live server_!
> It is only intended to upgrade projects in a development environment.

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
