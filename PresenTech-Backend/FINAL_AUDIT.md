PresenTech final audit

Verified statically:
- All PHP files under app/routes/database pass php -l.
- Duplicate /api/login route removed.
- CRUD destroy/restart methods now receive Request where Audit::log needs it.
- Student class_id update accepts null, so Admin can clear/assign class.
- API list endpoints support per_page with a bounded maximum of 1000.
- Admin password-reset endpoint supports server-side search/status and pagination.
- Password-reset completion is restricted to the user who owns the request.
- Notification store is restricted to admin; mark-read is ownership/role checked.
- Frontend includes pagination UI for students, teachers, classes, attendance, devices, RFID, system logs, schedules, corrections and password-reset admin list.
- Login Reset Password block is positioned above the Masuk button.
- Notification panel remains category-free.

Not hardware-tested by design:
- ESP32/MFRC522 physical tap
- SD card offline sync
- MQTT broker/device restart execution
- Physical device heartbeat

Runtime caveat:
- Full Vite dependency install/build could not be completed in the isolated environment because npm install timed out. Therefore runtime browser verification still needs to be run on the user's Laragon/Node machine.
