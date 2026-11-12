# PresenTech Integration Final

## Backend
1. Copy `.env.example` to `.env` and set MySQL database to `presentech`.
2. Run `composer install`.
3. Run `php artisan key:generate`.
4. Ensure Sanctum is installed (`laravel/sanctum` is in composer.json).
5. Run `php artisan migrate`.
6. Run `php artisan db:seed` to create the Admin account if the seeder is configured.
7. Run `php artisan serve`.

## Frontend
1. Run `npm install`.
2. Optional: create `.env` with `VITE_API_URL=http://127.0.0.1:8000/api`.
3. Run `npm run dev`.

The frontend sends the Sanctum bearer token automatically through Axios. CRUD master data, schedules, RFID, attendance reads, notifications, corrections, device management and system logs use the Laravel API when the user is authenticated. LocalStorage is retained only as a UI cache/fallback and theme/profile presentation cache.
