# Data Siswa API Integration

Komponen siap pakai:
`src/dashboard-admin/pages/StudentManagement.jsx`

Import di AdminDashboard:
```jsx
import StudentManagement from "./pages/StudentManagement";
```

Saat menu Data Siswa aktif, render:
```jsx
<StudentManagement />
```

Komponen ini memakai:
- GET /api/students
- POST /api/students
- PUT /api/students/{id}
- DELETE /api/students/{id}

Pastikan Laravel berjalan di:
http://127.0.0.1:8000

dan frontend:
http://localhost:5173
