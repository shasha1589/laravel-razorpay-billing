# Upgrade Guide

The package follows [Semantic Versioning](https://semver.org). While it is `0.x`, minor versions (`0.1` → `0.2`) may contain breaking changes; each is listed here with the steps to upgrade.

Always:

1. Read the [CHANGELOG](CHANGELOG.md) for the versions you skip.
2. Run `php artisan migrate`. New versions add migrations; published migrations are never changed.
3. If you published the config, compare it with the package's `config/razorpay-billing.php` for new options.

## 0.1.0

First release. Nothing to upgrade.
