# PresenTech Frontend - Laravel API Integration

Frontend menggunakan Axios untuk terhubung ke Laravel API.

Base URL:
http://127.0.0.1:8000/api

API modules:
- healthApi.js
- studentApi.js
- teacherApi.js
- classApi.js
- scheduleApi.js
- rfidApi.js
- attendanceApi.js
- correctionApi.js
- notificationApi.js
- deviceApi.js

Install:
npm install

Run frontend:
npm run dev

Run Laravel:
php artisan serve

Catatan:
Data utama aplikasi sebaiknya diambil dari Laravel/MySQL.
localStorage tetap digunakan untuk state UI seperti theme dan role sementara.
