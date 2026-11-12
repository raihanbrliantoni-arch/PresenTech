# PresenTech Sanctum Setup

Sanctum is required by the protected API routes. This project declares `laravel/sanctum` in composer.json.

From the backend folder run:

```powershell
composer install
php artisan optimize:clear
php artisan migrate
php artisan serve
```

If this is an existing installation and Composer reports that Sanctum is missing, run:

```powershell
composer require laravel/sanctum
php artisan optimize:clear
php artisan migrate
```

Do NOT run `migrate:fresh` unless you intentionally want to delete all database data.
