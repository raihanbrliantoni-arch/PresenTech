<?php

namespace Tests\Feature;

use App\Models\Schedule;
use App\Models\SchoolClass;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private function createSchedules(array $rows): SchoolClass
    {
        $admin = $this->makeAdmin();
        $teacher = Teacher::create([
            'nip' => 'NIP-TEST-1',
            'name' => 'Guru Uji',
            'status' => 'Aktif',
        ]);
        $class = SchoolClass::create([
            'name' => 'XI RPL 1',
            'major' => 'RPL',
            'student_count' => 0,
        ]);

        foreach ($rows as $row) {
            Schedule::create($row + [
                'class_id' => $class->id,
                'teacher_id' => $teacher->id,
                'academic_year' => '2026/2027',
                'semester' => 'Ganjil',
                'is_active' => true,
            ]);
        }

        $this->actingAs($admin);

        return $class;
    }

    public function test_index_yang_bebas_driver_database_mengurutkan_hari_dengan_benar(): void
    {
        $this->createSchedules([
            ['day' => 'Jumat', 'start_time' => '08:00', 'end_time' => '09:30', 'subject' => 'Produktif TKJ'],
            ['day' => 'Senin', 'start_time' => '09:00', 'end_time' => '10:30', 'subject' => 'Matematika'],
            ['day' => 'Senin', 'start_time' => '07:30', 'end_time' => '09:00', 'subject' => 'Pemrograman Web'],
            ['day' => 'Rabu', 'start_time' => '07:30', 'end_time' => '09:00', 'subject' => 'Produktif RPL'],
        ]);

        $response = $this->getJson('/api/schedules?per_page=100');

        $response->assertOk();

        $days = collect($response->json('data'))->pluck('day')->implode(',');
        $subjects = collect($response->json('data'))->pluck('subject')->implode(',');

        $this->assertSame('Senin,Senin,Rabu,Jumat', $days);
        $this->assertSame('Pemrograman Web,Matematika,Produktif RPL,Produktif TKJ', $subjects);
    }

    public function test_index_mine_guru_hanya_mengembalikan_jadwal_guru_sendiri(): void
    {
        $this->createSchedules([
            ['day' => 'Senin', 'start_time' => '07:30', 'end_time' => '09:00', 'subject' => 'Pemrograman Web'],
        ]);

        $guru = $this->makeGuru('guru-uji');

        $response = $this->actingAs($guru)->getJson('/api/schedules?mine=1');

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    private function makeSecondaryClass(string $name = 'XI TKJ 2'): SchoolClass
    {
        return SchoolClass::create([
            'name' => $name,
            'major' => 'TKJ',
            'student_count' => 0,
        ]);
    }

    public function test_jadwal_bentrok_kelas_ditolak(): void
    {
        $class = $this->createSchedules([
            ['day' => 'Senin', 'start_time' => '07:30', 'end_time' => '09:00', 'subject' => 'Pemrograman Web'],
        ]);

        $teacher = Teacher::firstOrFail();

        $this->postJson('/api/schedules', [
            'class_id' => $class->id,
            'teacher_id' => $teacher->id,
            'day' => 'Senin',
            'start_time' => '08:30',
            'end_time' => '09:30',
            'subject' => 'Matematika',
        ])->assertStatus(422)->assertJsonPath('message', fn ($message) => str_contains($message, 'Bentrok'));
    }

    public function test_jadwal_bentrok_guru_ditolak(): void
    {
        $this->createSchedules([
            ['day' => 'Senin', 'start_time' => '07:30', 'end_time' => '09:00', 'subject' => 'Pemrograman Web'],
        ]);

        $teacher = Teacher::firstOrFail();
        $otherClass = $this->makeSecondaryClass();

        $this->postJson('/api/schedules', [
            'class_id' => $otherClass->id,
            'teacher_id' => $teacher->id,
            'day' => 'Senin',
            'start_time' => '08:00',
            'end_time' => '09:30',
            'subject' => 'Matematika',
        ])->assertStatus(422)->assertJsonPath('message', fn ($message) => str_contains($message, 'guru'));
    }

    public function test_jadwal_bentrok_ruangan_ditolak(): void
    {
        $this->createSchedules([
            ['day' => 'Senin', 'start_time' => '07:30', 'end_time' => '09:00', 'subject' => 'Pemrograman Web', 'room' => 'Lab RPL 1'],
        ]);

        $otherClass = $this->makeSecondaryClass();
        $otherTeacher = Teacher::create(['nip' => 'NIP-TEST-2', 'name' => 'Guru Kedua', 'status' => 'Aktif']);

        $this->postJson('/api/schedules', [
            'class_id' => $otherClass->id,
            'teacher_id' => $otherTeacher->id,
            'day' => 'Senin',
            'start_time' => '07:45',
            'end_time' => '08:45',
            'subject' => 'Basis Data',
            'room' => 'Lab RPL 1',
        ])->assertStatus(422)->assertJsonPath('message', fn ($message) => str_contains($message, 'ruangan'));
    }

    public function test_jadwal_tidak_bentrok_tetap_disimpan(): void
    {
        $class = $this->createSchedules([
            ['day' => 'Senin', 'start_time' => '07:30', 'end_time' => '09:00', 'subject' => 'Pemrograman Web'],
        ]);

        $teacher = Teacher::firstOrFail();

        $this->postJson('/api/schedules', [
            'class_id' => $class->id,
            'teacher_id' => $teacher->id,
            'day' => 'Senin',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'subject' => 'Matematika',
        ])->assertCreated();
    }
}
