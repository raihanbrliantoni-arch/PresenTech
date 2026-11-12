<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Repo starter: database bersih, hanya satu akun Admin.
 *
 * Data kelas, guru, siswa, kartu RFID, jadwal, dan perangkat dibuat dari
 * dashboard Admin setelah login pertama. Kredensial awal dapat diatur
 * lewat variabel PRESENTECH_ADMIN_* di .env (lihat .env.example).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $username = trim((string) env('PRESENTECH_ADMIN_USERNAME', 'admin')) ?: 'admin';
        $name = trim((string) env('PRESENTECH_ADMIN_NAME', 'Administrator')) ?: 'Administrator';
        $password = (string) env('PRESENTECH_ADMIN_PASSWORD', 'admin123') ?: 'admin123';

        // Seeder aman dijalankan berulang: akun yang sudah ada tidak ditimpa.
        if (User::query()->where('username', $username)->exists()) {
            $this->command?->warn("Akun '{$username}' sudah ada. Seeder tidak mengubah data.");

            return;
        }

        $this->createUser([
            'name' => $name,
            'username' => $username,
            'password' => $password,
        ], 'admin');

        $this->command?->info("Akun Admin siap. Login dengan username '{$username}'.");
    }

    /**
     * User::create tidak menerima 'role' (bukan mass-assignable),
     * jadi role diisi eksplisit lewat method ini.
     */
    private function createUser(array $attributes, string $role): User
    {
        $user = new User($attributes);
        $user->role = $role;
        $user->save();

        return $user;
    }
}
