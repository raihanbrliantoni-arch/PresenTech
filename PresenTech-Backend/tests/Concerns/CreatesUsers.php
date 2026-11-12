<?php

namespace Tests\Concerns;

use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;

trait CreatesUsers
{
    protected function makeUser(string $role, string $username, string $password = 'password123'): User
    {
        $user = new User([
            'name' => ucfirst($username),
            'username' => $username,
            'password' => $password,
        ]);
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makeAdmin(string $username = 'admin'): User
    {
        return $this->makeUser('admin', $username, 'admin12345');
    }

    protected function makeGuru(string $username = 'guru'): User
    {
        $user = $this->makeUser('guru', $username, 'guru12345');

        Teacher::create([
            'user_id' => $user->id,
            'nip' => 'NIP-'.$user->id,
            'name' => $user->name,
            'status' => 'Aktif',
        ]);

        return $user->fresh();
    }

    protected function makeSiswa(string $username = 'siswa', string $nis = '10001'): User
    {
        $user = $this->makeUser('siswa', $username, 'siswa12345');

        Student::create([
            'user_id' => $user->id,
            'nis' => $nis,
            'name' => $user->name,
            'status' => 'Aktif',
        ]);

        return $user->fresh();
    }
}
