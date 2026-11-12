<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class ReportAttendanceTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private SchoolClass $kelasA;

    private SchoolClass $kelasB;

    protected function setUp(): void
    {
        parent::setUp();

        $guru = $this->makeGuru('walikelas1');
        $this->kelasA = SchoolClass::create([
            'name' => 'XI RPL 1',
            'major' => 'RPL',
            'homeroom_teacher_id' => $guru->teacher->id,
            'student_count' => 2,
        ]);

        $this->kelasB = SchoolClass::create([
            'name' => 'XI TKJ 1',
            'major' => 'TKJ',
            'student_count' => 1,
        ]);

        $siswaA = $this->makeSiswa('siswaA', '10001');
        $siswaA->student->update(['class_id' => $this->kelasA->id]);

        $siswaB = $this->makeSiswa('siswaB', '10002');
        $siswaB->student->update(['class_id' => $this->kelasB->id]);
    }

    private function catat(string $nis, string $tanggal, string $jam = '07:00:00', string $status = 'Hadir'): void
    {
        $student = Student::where('nis', $nis)->firstOrFail();

        Attendance::create([
            'student_id' => $student->id,
            'attendance_date' => $tanggal,
            'attendance_time' => $jam,
            'status' => $status,
            'source' => 'RFID',
        ]);
    }

    public function test_laporan_harian_dikelompokkan_per_kelas_dengan_wali_kelas(): void
    {
        $this->catat('10001', '2026-10-28', '07:00:00', 'Hadir');
        $this->catat('10001', '2026-10-28', '08:00:00', 'Terlambat');
        $this->catat('10002', '2026-10-28', '07:10:00', 'Hadir');
        $this->catat('10001', '2026-10-27', '07:00:00', 'Hadir');

        $response = $this->actingAs($this->makeAdmin())
            ->getJson('/api/reports/attendance?period=day&date=2026-10-28');

        $response->assertOk()
            ->assertJsonPath('period.type', 'day')
            ->assertJsonPath('period.from', '2026-10-28')
            ->assertJsonPath('period.to', '2026-10-28')
            ->assertJsonPath('total', 3)
            ->assertJsonPath('classes.0.name', 'XI RPL 1')
            ->assertJsonPath('classes.0.homeroom', 'Walikelas1')
            ->assertJsonPath('classes.0.total', 2)
            ->assertJsonPath('classes.0.summary.Hadir', 1)
            ->assertJsonPath('classes.0.summary.Terlambat', 1)
            ->assertJsonPath('classes.1.name', 'XI TKJ 1')
            ->assertJsonPath('classes.1.homeroom', null)
            ->assertJsonPath('classes.1.total', 1);
    }

    public function test_laporan_menampilkan_jam_pulang_dan_menandai_pulang_awal(): void
    {
        $student = Student::where('nis', '10001')->firstOrFail();

        Attendance::create([
            'student_id' => $student->id,
            'attendance_date' => '2026-10-28',
            'attendance_time' => '07:00:00',
            'check_out_time' => '10:00:00',
            'early_leave' => true,
            'status' => 'Hadir',
            'source' => 'Manual',
        ]);

        $this->actingAs($this->makeAdmin())
            ->getJson('/api/reports/attendance?period=day&date=2026-10-28&class_id='.$this->kelasA->id)
            ->assertOk()
            ->assertJsonPath('classes.0.rows.0.time', '07:00')
            ->assertJsonPath('classes.0.rows.0.check_out', '10:00')
            ->assertJsonPath('classes.0.rows.0.early_leave', true)
            ->assertJsonPath('classes.0.check_out_missing', 0)
            ->assertJsonPath('classes.0.early_leave', 1);
    }

    public function test_laporan_rentang_tanggal_bebas(): void
    {
        $this->catat('10001', '2026-10-01', '07:00:00', 'Hadir');
        $this->catat('10001', '2026-10-05', '07:00:00', 'Hadir');
        $this->catat('10001', '2026-10-09', '07:00:00', 'Hadir');

        $this->actingAs($this->makeAdmin())
            ->getJson('/api/reports/attendance?period=range&from=2026-10-02&to=2026-10-08')
            ->assertOk()
            ->assertJsonPath('period.type', 'range')
            ->assertJsonPath('period.from', '2026-10-02')
            ->assertJsonPath('period.to', '2026-10-08')
            ->assertJsonPath('period.label', 'Rentang 2 Oktober 2026 - 8 Oktober 2026')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('classes.0.rows.0.date', '2026-10-05');
    }

    public function test_laporan_bisa_disaring_berdasarkan_jam_tap(): void
    {
        $this->catat('10001', '2026-10-28', '06:45:00', 'Hadir');
        $this->catat('10002', '2026-10-28', '07:30:00', 'Terlambat');

        $this->actingAs($this->makeAdmin())
            ->getJson('/api/reports/attendance?period=day&date=2026-10-28&from_time=07:00&to_time=08:00')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('classes.1.rows.0.time', '07:30')
            ->assertJsonPath('period.label', 'Harian 28 Oktober 2026 • jam 07:00 - 08:00');
    }

    public function test_rentang_tanggal_wajib_isi_dari_dan_sampai(): void
    {
        $this->actingAs($this->makeAdmin())
            ->getJson('/api/reports/attendance?period=range')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from', 'to']);
    }

    public function test_baris_diurutkan_sesuai_nomor_absen_nama_siswa(): void
    {
        // Budi dicatat lebih dulu, tetapi nomor absen mengikuti urutan nama A-Z.
        $budi = $this->makeSiswa('budi', '10003');
        $budi->student->update(['class_id' => $this->kelasA->id]);
        $ani = $this->makeSiswa('ani', '10004');
        $ani->student->update(['class_id' => $this->kelasA->id]);

        $this->catat('10003', '2026-10-28', '07:00:00', 'Hadir');
        $this->catat('10004', '2026-10-28', '07:05:00', 'Hadir');

        $this->actingAs($this->makeAdmin())
            ->getJson('/api/reports/attendance?period=day&date=2026-10-28&class_id='.$this->kelasA->id)
            ->assertOk()
            ->assertJsonPath('classes.0.rows.0.name', 'Ani')
            ->assertJsonPath('classes.0.rows.0.no', 1)
            ->assertJsonPath('classes.0.rows.1.name', 'Budi')
            ->assertJsonPath('classes.0.rows.1.no', 2);
    }

    public function test_kelas_tanpa_data_tetap_muncul_saat_semua_kelas_dipilih(): void
    {
        $this->catat('10001', '2026-10-28');

        $response = $this->actingAs($this->makeAdmin())
            ->getJson('/api/reports/attendance?period=day&date=2026-10-28');

        $response->assertOk()
            ->assertJsonCount(2, 'classes')
            ->assertJsonPath('classes.1.name', 'XI TKJ 1')
            ->assertJsonPath('classes.1.total', 0);
    }

    public function test_filter_kelas_hanya_mengembalikan_kelas_tersebut(): void
    {
        $this->catat('10001', '2026-10-28');
        $this->catat('10002', '2026-10-28');

        $response = $this->actingAs($this->makeAdmin())
            ->getJson('/api/reports/attendance?period=day&date=2026-10-28&class_id='.$this->kelasB->id);

        $response->assertOk()
            ->assertJsonCount(1, 'classes')
            ->assertJsonPath('classes.0.name', 'XI TKJ 1')
            ->assertJsonPath('total', 1);
    }

    public function test_periode_mingguan_senin_sampai_minggu(): void
    {
        // 2026-10-28 = Rabu. Senin 26 Okt, Minggu 1 Nov.
        $this->catat('10001', '2026-10-25'); // Minggu sebelumnya (di luar)
        $this->catat('10001', '2026-10-26'); // Senin (masuk)
        $this->catat('10001', '2026-11-01'); // Minggu (masuk)
        $this->catat('10001', '2026-11-02'); // Senin berikutnya (di luar)

        $response = $this->actingAs($this->makeAdmin())
            ->getJson('/api/reports/attendance?period=week&date=2026-10-28');

        $response->assertOk()
            ->assertJsonPath('period.from', '2026-10-26')
            ->assertJsonPath('period.to', '2026-11-01')
            ->assertJsonPath('total', 2);
    }

    public function test_periode_tiga_dan_enam_bulan_mundur_dari_bulan_acuan(): void
    {
        $this->catat('10001', '2026-04-30'); // di luar 3 bulan (Agustus-Oktober)
        $this->catat('10001', '2026-05-15'); // hanya masuk rentang 6 bulan
        $this->catat('10001', '2026-08-01'); // batas awal 3 bulan
        $this->catat('10001', '2026-10-31'); // batas akhir

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->getJson('/api/reports/attendance?period=3months&date=2026-10-15')
            ->assertOk()
            ->assertJsonPath('period.from', '2026-08-01')
            ->assertJsonPath('period.to', '2026-10-31')
            ->assertJsonPath('total', 2);

        $this->actingAs($admin)
            ->getJson('/api/reports/attendance?period=6months&date=2026-10-15')
            ->assertOk()
            ->assertJsonPath('period.from', '2026-05-01')
            ->assertJsonPath('total', 3);
    }

    public function test_periode_tahunan_mencakup_satu_tahun_penuh(): void
    {
        $this->catat('10001', '2025-12-31'); // di luar
        $this->catat('10001', '2026-01-01');
        $this->catat('10001', '2026-12-31');

        $this->actingAs($this->makeAdmin())
            ->getJson('/api/reports/attendance?period=year&date=2026-06-01')
            ->assertOk()
            ->assertJsonPath('period.from', '2026-01-01')
            ->assertJsonPath('period.to', '2026-12-31')
            ->assertJsonPath('total', 2);
    }

    public function test_siswa_tidak_boleh_mengakses_laporan(): void
    {
        $this->actingAs($this->makeSiswa('siswaC', '10099'))
            ->getJson('/api/reports/attendance?period=day&date=2026-10-28')
            ->assertForbidden();
    }

    public function test_periode_tidak_valid_ditolak(): void
    {
        $this->actingAs($this->makeAdmin())
            ->getJson('/api/reports/attendance?period=dekade&date=2026-10-28')
            ->assertUnprocessable();
    }

    public function test_include_absent_menambahkan_siswa_tanpa_catatan_sebagai_alpa(): void
    {
        $kelas = $this->kelasA;
        $tanpaTap = $this->makeSiswa('tanpatapp', '10007');
        $tanpaTap->student->update(['class_id' => $kelas->id, 'no_absen' => 2]);

        $this->catat('10001', '2026-10-28');

        $response = $this->actingAs($this->makeAdmin())
            ->getJson('/api/reports/attendance?period=day&date=2026-10-28&class_id='.$kelas->id.'&include_absent=1');

        $response->assertOk()
            ->assertJsonPath('classes.0.total', 2)
            ->assertJsonPath('classes.0.summary.Alpa', 1);

        $statuses = collect($response->json('classes.0.rows'))->pluck('status')->all();
        $this->assertContains('Alpa', $statuses);
    }

    public function test_nomor_absen_permanen_dipakai_untuk_urutan(): void
    {
        $kelas = $this->kelasA;
        $ani = $this->makeSiswa('aniabsen', '10008');
        $ani->student->update(['class_id' => $kelas->id, 'no_absen' => 7]);
        $budi = $this->makeSiswa('budiabsen', '10009');
        $budi->student->update(['class_id' => $kelas->id, 'no_absen' => 2]);

        $this->catat('10008', '2026-10-28');
        $this->catat('10009', '2026-10-28');

        $response = $this->actingAs($this->makeAdmin())
            ->getJson('/api/reports/attendance?period=day&date=2026-10-28&class_id='.$kelas->id);

        $response->assertOk()
            ->assertJsonPath('classes.0.rows.0.name', 'Budiabsen')
            ->assertJsonPath('classes.0.rows.0.no', 2)
            ->assertJsonPath('classes.0.rows.1.name', 'Aniabsen')
            ->assertJsonPath('classes.0.rows.1.no', 7);
    }

    public function test_sort_nama_tetap_menampilkan_nomor_absen_asli(): void
    {
        $kelas = $this->kelasA;
        $ani = $this->makeSiswa('anisort', '10010');
        $ani->student->update(['class_id' => $kelas->id, 'no_absen' => 9]);
        $budi = $this->makeSiswa('budisort', '10011');
        $budi->student->update(['class_id' => $kelas->id, 'no_absen' => 3]);

        $this->catat('10010', '2026-10-28');
        $this->catat('10011', '2026-10-28');

        $this->actingAs($this->makeAdmin())
            ->getJson('/api/reports/attendance?period=day&date=2026-10-28&class_id='.$kelas->id.'&sort=name')
            ->assertOk()
            ->assertJsonPath('sort', 'name')
            ->assertJsonPath('classes.0.rows.0.name', 'Anisort')
            ->assertJsonPath('classes.0.rows.0.no', 9)
            ->assertJsonPath('classes.0.rows.1.name', 'Budisort')
            ->assertJsonPath('classes.0.rows.1.no', 3);
    }

    public function test_rentang_satu_hari_libur_ditandai(): void
    {
        Holiday::create([
            'date' => '2026-12-25',
            'name' => 'Hari Raya Natal',
            'type' => 'nasional',
        ]);

        $this->actingAs($this->makeAdmin())
            ->getJson('/api/reports/attendance?period=range&from=2026-12-25&to=2026-12-25&include_absent=1')
            ->assertOk()
            ->assertJsonPath('is_holiday', true)
            ->assertJsonPath('holiday.name', 'Hari Raya Natal')
            ->assertJsonPath('total', 0);
    }

    public function test_laporan_semester_memakai_batas_tahun_ajaran(): void
    {
        $this->catat('10001', '2026-09-10');
        $this->catat('10001', '2027-02-10');
        $this->catat('10001', '2027-07-10');

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->getJson('/api/reports/attendance?period=semester&semester=Ganjil&academic_year=2026/2027')
            ->assertOk()
            ->assertJsonPath('period.type', 'semester')
            ->assertJsonPath('period.from', '2026-07-01')
            ->assertJsonPath('period.to', '2026-12-31')
            ->assertJsonPath('period.label', 'Semester Ganjil 2026/2027')
            ->assertJsonPath('total', 1);

        $this->actingAs($admin)
            ->getJson('/api/reports/attendance?period=semester&semester=Genap&academic_year=2026/2027')
            ->assertOk()
            ->assertJsonPath('period.from', '2027-01-01')
            ->assertJsonPath('period.to', '2027-06-30')
            ->assertJsonPath('period.label', 'Semester Genap 2026/2027')
            ->assertJsonPath('total', 1);
    }

    public function test_semester_dapat_dipilih_tanpa_period(): void
    {
        $this->catat('10001', '2026-09-10');

        $this->actingAs($this->makeAdmin())
            ->getJson('/api/reports/attendance?semester=Ganjil&academic_year=2026/2027')
            ->assertOk()
            ->assertJsonPath('period.type', 'semester')
            ->assertJsonPath('total', 1);
    }

    public function test_tahun_ajaran_tidak_valid_ditolak(): void
    {
        $this->actingAs($this->makeAdmin())
            ->getJson('/api/reports/attendance?period=semester&academic_year=2026-2027')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('academic_year');
    }

    public function test_rekap_per_kelas_menyertakan_jumlah_siswa_dan_persentase(): void
    {
        $this->catat('10001', '2026-10-28', '07:00:00', 'Hadir');
        $this->catat('10002', '2026-10-28', '07:10:00', 'Terlambat');

        $this->actingAs($this->makeAdmin())
            ->getJson('/api/reports/attendance?period=day&date=2026-10-28')
            ->assertOk()
            ->assertJsonPath('class_summaries.0.name', 'XI RPL 1')
            ->assertJsonPath('class_summaries.0.students', 1)
            ->assertJsonPath('class_summaries.0.total', 1)
            ->assertJsonPath('class_summaries.0.summary.Hadir', 1)
            ->assertJsonPath('class_summaries.0.attendance_rate', 100)
            ->assertJsonPath('class_summaries.1.name', 'XI TKJ 1')
            ->assertJsonPath('class_summaries.1.summary.Terlambat', 1)
            ->assertJsonPath('class_summaries.1.attendance_rate', 100);
    }

    public function test_include_absent_tidak_mengisi_alpa_pada_hari_libur(): void
    {
        Holiday::create([
            'date' => '2026-12-25',
            'name' => 'Hari Raya Natal',
            'type' => 'nasional',
        ]);

        $this->actingAs($this->makeAdmin())
            ->getJson('/api/reports/attendance?period=day&date=2026-12-25&include_absent=1')
            ->assertOk()
            ->assertJsonPath('is_holiday', true)
            ->assertJsonPath('holiday.name', 'Hari Raya Natal')
            ->assertJsonPath('total', 0);
    }
}
