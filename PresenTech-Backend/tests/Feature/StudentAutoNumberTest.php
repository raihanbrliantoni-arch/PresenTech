<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class StudentAutoNumberTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private function makeClass(): SchoolClass
    {
        return SchoolClass::create([
            'name' => 'X RPL 1',
            'major' => 'RPL',
            'student_count' => 0,
        ]);
    }

    public function test_nomor_absen_diisi_urut_nama_dan_tidak_menimpa_yang_sudah_ada(): void
    {
        $class = $this->makeClass();
        $admin = $this->makeAdmin();

        $ani = $this->makeSiswa('aniurut', '40001');
        $ani->student->update(['class_id' => $class->id]);
        $budi = $this->makeSiswa('budiurut', '40002');
        $budi->student->update(['class_id' => $class->id, 'no_absen' => 5]);
        $citra = $this->makeSiswa('citraurut', '40003');
        $citra->student->update(['class_id' => $class->id]);

        $this->actingAs($admin)
            ->postJson('/api/students/auto-number', ['overwrite' => false])
            ->assertOk()
            ->assertJsonPath('updated', 2);

        $this->assertSame(6, Student::find($ani->student->id)->no_absen);
        $this->assertSame(5, Student::find($budi->student->id)->no_absen);
        $this->assertSame(7, Student::find($citra->student->id)->no_absen);
    }

    public function test_overwrite_menomori_ulang_semua_siswa_per_kelas(): void
    {
        $class = $this->makeClass();
        $admin = $this->makeAdmin();

        foreach (['citraover', 'aniover', 'budiover'] as $index => $username) {
            $siswa = $this->makeSiswa($username, '4001'.$index);
            $siswa->student->update(['class_id' => $class->id, 'no_absen' => 99]);
        }

        $this->actingAs($admin)
            ->postJson('/api/students/auto-number', ['overwrite' => true])
            ->assertOk();

        $this->assertSame(
            [1, 2, 3],
            Student::where('class_id', $class->id)->orderBy('name')->pluck('no_absen')->all(),
        );
    }

    public function test_non_admin_tidak_bisa_mengatur_nomor_absen(): void
    {
        $this->actingAs($this->makeSiswa('siswaauto', '40009'))
            ->postJson('/api/students/auto-number')
            ->assertForbidden();
    }
}
