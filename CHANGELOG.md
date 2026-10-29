# Release Notes

## 1.3.0 - 2026-10-29

- The command now rewrites `dirname(__DIR__)`-based paths in `config/craft/general.php` to `base_path()` or `public_path()`, and removes the `omitScriptNameInUrls`, `pathParam`, and `enableTwigSandbox` settings.
- The command now sets `APP_ENV` and `APP_URL` in `.env`, maps `CRAFT_DB_TABLE` to `DB_TABLE_PREFIX`, and renames the variables in `.env.example*` files too.
- The command now removes `yiisoft/yii2-shell` from `composer.json`.
- The command now adds `/public/vendor/craft`, `/public/storage`, and `/storage/*.key` to `.gitignore`.
- Filesystem disk suggestions now use `public_path()`, `base_path()`, `storage_path()`, and `env()` instead of Craft aliases and `$ENV_VAR` references.
- The command now asks before continuing if the project has uncommitted Git changes, or if `bootstrap/` or `config/craft/` already exist.
- The command now shows the customized lines of `index.php` and `bootstrap.php` that will be lost.
- The command now reports which packages that require `craftcms/cms` have a Craft 6 version on Packagist.
- The command now suggests next steps for multi-environment and app-type config files, `db.php`, Yii config in `app.php`, `routes.php`, custom HTMLPurifier configs, category groups, tag groups, and global sets, and the email transport.
- Next steps are now wrapped to the terminal width.
- The next steps now end with running `artisan craft:up`.
- Steps with nothing to do now say so.

## 1.2.0 - 2026-09-20

- The command now creates Laravel’s default configuration files, and suggests updating `config/filesystems.php` based on the previously-defined Craft filesystems. ([#5](https://github.com/craftcms/craft6-revamp/pull/5))

## 1.1.1 - 2026-07-07

- Improved the Next Steps formatting.

## 1.1.0 - 2026-07-07

- Added suggestion to add `loginPath` to `config/craft/general.php`.

## 1.0.0 - 2026-05-06

- Initial release with support for Craft CMS 6.0.0-alpha.1
