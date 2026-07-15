# DogeOW Repo Watch API

Independent Laravel API for monitoring npm and Composer dependencies from GitHub repositories.

## Boundaries

- One application database: `repo_watch`.
- Central DogeOW accounts are received through a short-lived SSO ticket and kept only as an encrypted session identity snapshot.
- No SQL connection or foreign key points to the central account database.
- GitHub push/release webhooks and an hourly scheduler refresh package versions.

## Local verification

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

Production provisioning and verified database migration live in the repository-level `scripts/` directory.
