# PresenTech Fix Audit

- Guru dashboard refreshes `/api/me` before loading students, attendance, and schedules; fake sample students/attendance were removed.
- Siswa dashboard refreshes `/api/me` before resolving class and loading schedules, so Admin class assignment is reflected after re-login/refresh.
- Added authenticated `GET /api/schedules?mine=1` support for role-aware schedule retrieval.
- Generic Admin edit/save modal now has a saving lock and buttons cannot submit twice.
- Row action buttons explicitly use `type="button"` to prevent accidental form submission.
- Schedule day/edit/delete buttons explicitly use `type="button"`.
- Existing UI structure is preserved; changes are functional/data-flow fixes.
- Backend still requires Laravel Sanctum to be installed locally (`composer install` from the supplied backend with the matching composer.lock).
