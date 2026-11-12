PresenTech frontend final audit

Static review completed:
- Login keeps NIS/NIP and places Reset Password above Masuk.
- Admin reset-password page has API pagination (20/page).
- Admin list pages have pagination UI for core data.
- Notification UI has no category/filter bar.
- API client attaches Sanctum bearer token and clears auth storage on 401.
- GuruDashboard and SiswaDashboard use default exports.
- AdminDashboard includes ConfirmModal.
- Core API modules exist for auth, students, teachers, classes, schedules, RFID, attendance, corrections, notifications, devices and system logs.

Runtime caveat:
- npm ci/build timed out in the isolated environment, so browser runtime must be checked after npm install on the user's machine.
