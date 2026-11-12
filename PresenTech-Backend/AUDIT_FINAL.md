# PresenTech Backend Audit - 13 September 2026

Changes in this audit:
- Login and /me now return student.schoolClass so class/jurusan reach the dashboard immediately.
- Removed duplicate admin authorization check in NotificationController.
- Removed duplicate ownership check in password reset completion.
- Student class changes notify the linked student account.
- Teacher master-data changes notify the linked teacher account.
- RFID registration notifies the linked student account.
- Attendance endpoint now supports RFID UID, student_id, or NIS; NIS/manual attendance is stored with source=Manual.
- RFID attendance defaults to Hadir before 07:15 and Terlambat after 07:15 when status is not explicitly supplied.
- Existing pagination endpoints remain active.
- All API controller PHP files pass PHP syntax checks.
