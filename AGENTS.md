# Repository guidance

This is a WordPress cache management plugin. Read `README.md` for supported features, requirements, and installation steps. Keep plugin changes compatible with PHP 8.1+ and WordPress 6.0+.

## Checks

The CI workflow runs PHP lint with:

```sh
find src/ -name '*.php' -print0 | xargs -0 -n1 php -l
```

CI runs `vendor/bin/phpunit` only when `tests/` exists. Install Composer dependencies first if `composer.json` is present. The current repository has no `tests/` directory or `composer.json`, so CI currently runs the PHP lint step only.

## Deployment

A push to `main` runs `.github/workflows/notify-deploy.yml`, which sends a plugin-updated event to deployment automation. Keep changes on a pull request branch and do not push directly to `main` without explicit authorization.
