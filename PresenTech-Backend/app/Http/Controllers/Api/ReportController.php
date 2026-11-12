<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Student;
use App\Support\ClassOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Laporan absensi untuk export (PDF/CSV) dengan filter periode dan kelas.
 * Data dikelompokkan per kelas beserta nama wali kelas agar laporan
 * "semua kelas" tetap terbaca per kelas.
 */
class ReportController extends Controller
{
    private const MAX_ROWS = 20000;

    private const MONTHS = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    public function attendance(Request $request)
    {
        $data = $request->validate([
            'period' => ['nullable', Rule::in(['day', 'week', 'month', '3months', '6months', 'year', 'semester', 'range'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
            // Filter semester memakai tahun ajaran "2026/2027". Bila tidak
            // diisi, nilai aktif di Pengaturan dipakai.
            'semester' => ['nullable', Rule::in(['Ganjil', 'Genap'])],
            'academic_year' => ['nullable', 'string', 'regex:/^\d{4}\/\d{4}$/'],
            // Rentang bebas menerima tanggal saja (Y-m-d) maupun datetime
            // (Y-m-d\TH:i) untuk export "date time to date time".
            'from' => ['nullable', 'date', Rule::requiredIf($request->input('period') === 'range')],
            'to' => [
                'nullable',
                'date',
                Rule::requiredIf($request->input('period') === 'range'),
                Rule::when($request->filled('from'), ['after_or_equal:from']),
            ],
            'from_time' => ['nullable', 'date_format:H:i'],
            'to_time' => ['nullable', 'date_format:H:i', Rule::when($request->filled('from_time'), ['after_or_equal:from_time'])],
            'class_id' => ['nullable', 'integer', 'exists:school_classes,id'],
            'status' => ['nullable', Rule::in(['Hadir', 'Terlambat', 'Izin', 'Sakit', 'Alpa', 'Dikoreksi'])],
            'include_absent' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['no_absen', 'name', 'nis'])],
        ]);

        $hasRange = ! empty($data['from']) || ! empty($data['to']);
        $period = $data['period'] ?? (! empty($data['semester'])
            ? 'semester'
            : ($hasRange ? 'range' : 'month'));
        $anchor = Carbon::createFromFormat('Y-m-d', $data['date'] ?? now()->toDateString())->startOfDay();
        [$from, $to, $label] = $this->rangeFor($period, $anchor, $data);

        $query = Attendance::query()
            ->with(['student.schoolClass.homeroomTeacher'])
            ->whereDate('attendance_date', '>=', $from->toDateString())
            ->whereDate('attendance_date', '<=', $to->toDateString())
            ->orderBy('attendance_date')
            ->orderBy('attendance_time');

        // Batas jam hanya perlu dipotong bila Admin memilih datetime
        // (mis. 01/11/2026 06:00 - 01/11/2026 17:00), bukan tanggal penuh.
        if ($from->format('H:i:s') !== '00:00:00') {
            $query->where(fn ($builder) => $builder
                ->whereDate('attendance_date', '>', $from->toDateString())
                ->orWhere('attendance_time', '>=', $from->format('H:i:s')));
        }

        if ($to->format('H:i:s') !== '23:59:59') {
            $query->where(fn ($builder) => $builder
                ->whereDate('attendance_date', '<', $to->toDateString())
                ->orWhere('attendance_time', '<=', $to->format('H:i:s')));
        }

        if (! empty($data['class_id'])) {
            $query->whereHas('student', fn ($builder) => $builder->where('class_id', $data['class_id']));
        }

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        // Filter jam (opsional): berguna untuk melihat tap pada rentang jam
        // tertentu, mis. siapa yang tap antara 06:30 dan 07:15.
        if (! empty($data['from_time'])) {
            $query->where('attendance_time', '>=', $data['from_time'].':00');
        }

        if (! empty($data['to_time'])) {
            $query->where('attendance_time', '<=', $data['to_time'].':59');
        }

        $records = $query->limit(self::MAX_ROWS + 1)->get();
        $truncated = $records->count() > self::MAX_ROWS;
        $records = $records->take(self::MAX_ROWS);

        $classes = ClassOrder::sort(
            SchoolClass::with('homeroomTeacher')
                ->when(! empty($data['class_id']), fn ($builder) => $builder->whereKey($data['class_id']))
                ->get()
        );

        $rowGroups = [];
        $studentIdsByClass = [];

        foreach ($records as $record) {
            $student = $record->student;
            $classId = $student?->class_id ?? 0;

            if ($student !== null) {
                $studentIdsByClass[$classId][$student->id] = true;
            }

            $rowGroups[$classId][] = [
                'no' => 0,
                'no_absen' => $student?->no_absen,
                'nis' => $student?->nis,
                'name' => $student?->name,
                'date' => $record->attendance_date->toDateString(),
                'time' => substr((string) $record->attendance_time, 0, 5),
                'check_out' => $record->check_out_time ? substr((string) $record->check_out_time, 0, 5) : '-',
                'early_leave' => (bool) $record->early_leave,
                'notes' => $record->notes,
                'status' => $record->status,
            ];
        }

        // Opsi "tampilkan semua siswa" hanya relevan untuk laporan harian:
        // siswa aktif tanpa catatan pada tanggal itu diisi Alpa agar nomor
        // absen tetap lengkap 1..n. Pada hari libur baris Alpa tidak diisi.
        // Status libur juga dikirim untuk rentang satu tanggal agar export
        // harian bisa memberi catatan.
        $singleDay = $period === 'day' || ($period === 'range' && $from->isSameDay($to));
        $holiday = $singleDay ? Holiday::forDate($from) : null;
        $includeAbsent = (bool) ($data['include_absent'] ?? false) && $period === 'day' && $holiday === null;

        if ($includeAbsent) {
            $absentStudents = Student::query()
                ->where('status', 'Aktif')
                ->when(! empty($data['class_id']), fn ($builder) => $builder->where('class_id', $data['class_id']))
                ->orderBy('name')
                ->get();

            foreach ($absentStudents as $student) {
                $classId = $student->class_id ?? 0;

                if (isset($studentIdsByClass[$classId][$student->id])) {
                    continue;
                }

                $rowGroups[$classId][] = [
                    'no' => 0,
                    'no_absen' => $student->no_absen,
                    'nis' => $student->nis,
                    'name' => $student->name,
                    'date' => $anchor->toDateString(),
                    'time' => '-',
                    'check_out' => '-',
                    'early_leave' => false,
                    'notes' => null,
                    'status' => 'Alpa',
                ];
            }
        }

        $sortMode = $data['sort'] ?? 'no_absen';

        // Urutan baris per kelas. Default mengikuti Nomor Absen di data siswa
        // (bukan nama), sehingga nama tidak "ngacak" di hasil export.
        foreach ($rowGroups as $classId => $rows) {
            $sortByStudent = $sortMode === 'nis'
                ? fn (array $row) => (string) ($row['nis'] ?? '')
                : fn (array $row) => mb_strtolower((string) $row['name']);

            usort($rows, fn (array $a, array $b) => strcmp($sortByStudent($a), $sortByStudent($b))
                ?: strcmp((string) $a['date'], (string) $b['date'])
                ?: strcmp((string) $a['time'], (string) $b['time']));

            $rankByStudent = [];
            $rank = 0;
            $maxNoAbsen = 0;

            foreach ($rows as $row) {
                if ($row['no_absen'] !== null) {
                    $maxNoAbsen = max($maxNoAbsen, (int) $row['no_absen']);
                }
            }

            foreach ($rows as $index => $row) {
                $studentKey = ($row['nis'] ?? '').'|'.$row['name'];

                if (! isset($rankByStudent[$studentKey])) {
                    $rankByStudent[$studentKey] = $sortMode === 'no_absen'
                        ? ($row['no_absen'] ?? ($maxNoAbsen + (++$rank)))
                        : ($row['no_absen'] ?? (++$rank));
                }

                $rows[$index]['no'] = $rankByStudent[$studentKey];
            }

            if ($sortMode === 'no_absen') {
                usort($rows, fn (array $a, array $b) => ((int) $a['no'] <=> (int) $b['no'])
                    ?: strcmp((string) $a['date'], (string) $b['date'])
                    ?: strcmp((string) $a['time'], (string) $b['time']));
            } else {
                usort($rows, fn (array $a, array $b) => strcmp($sortByStudent($a), $sortByStudent($b))
                    ?: strcmp((string) $a['date'], (string) $b['date'])
                    ?: strcmp((string) $a['time'], (string) $b['time']));
            }

            $rowGroups[$classId] = $rows;
        }

        // Peringatan untuk Admin: nomor absen kosong/ganda membuat urutan
        // export tidak bisa konsisten.
        $missingNoAbsen = 0;
        $duplicateNoAbsen = 0;

        foreach ($rowGroups as $rows) {
            $seenStudents = [];
            $seenNumbers = [];

            foreach ($rows as $row) {
                $studentKey = ($row['nis'] ?? '').'|'.$row['name'];

                if (isset($seenStudents[$studentKey])) {
                    continue;
                }

                $seenStudents[$studentKey] = true;

                if ($row['no_absen'] === null) {
                    $missingNoAbsen++;

                    continue;
                }

                $number = (string) $row['no_absen'];
                $seenNumbers[$number] = ($seenNumbers[$number] ?? 0) + 1;
            }

            foreach ($seenNumbers as $count) {
                if ($count > 1) {
                    $duplicateNoAbsen++;
                }
            }
        }

        $groups = $classes->map(fn (SchoolClass $class) => $this->group(
            $class->id,
            $class->name,
            $class->homeroomTeacher?->name,
            $rowGroups[$class->id] ?? [],
        ))->values()->all();

        // Siswa tanpa kelas tetap ditampilkan agar laporan tidak "menghilang".
        if (empty($data['class_id']) && ! empty($rowGroups[0])) {
            $groups[] = $this->group(null, 'Tanpa Kelas', null, $rowGroups[0]);
        }

        $totalRows = array_sum(array_map(fn (array $group) => count($group['rows']), $groups));

        // Rekap ringkas per kelas untuk halaman Laporan: jumlah siswa aktif,
        // total catatan, dan persentase kehadiran (Hadir + Terlambat).
        $studentCounts = Student::query()
            ->where('status', 'Aktif')
            ->whereNotNull('class_id')
            ->when(! empty($data['class_id']), fn ($builder) => $builder->where('class_id', $data['class_id']))
            ->selectRaw('class_id, count(*) as total')
            ->groupBy('class_id')
            ->pluck('total', 'class_id');

        $classSummaries = array_map(function (array $group) use ($studentCounts): array {
            $present = ($group['summary']['Hadir'] ?? 0) + ($group['summary']['Terlambat'] ?? 0);
            $rate = $group['total'] > 0 ? round($present / $group['total'] * 100, 1) : 0.0;

            return [
                'id' => $group['id'],
                'name' => $group['name'],
                'homeroom' => $group['homeroom'],
                'students' => (int) ($studentCounts[$group['id']] ?? 0),
                'total' => $group['total'],
                'summary' => $group['summary'],
                'check_out_missing' => $group['check_out_missing'],
                'attendance_rate' => $rate,
            ];
        }, $groups);

        return response()->json([
            'period' => [
                'type' => $period,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'label' => $label,
            ],
            'total' => $totalRows,
            'truncated' => $truncated,
            'sort' => $sortMode,
            'class_summaries' => $classSummaries,
            'is_holiday' => $holiday !== null,
            'holiday' => $holiday === null ? null : [
                'date' => $holiday->date->toDateString(),
                'name' => $holiday->name,
                'type' => $holiday->type,
            ],
            'warnings' => [
                'missing_no_absen' => $missingNoAbsen,
                'duplicate_no_absen' => $duplicateNoAbsen,
            ],
            'classes' => $groups,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{id: int|null, name: string, homeroom: string|null, total: int, check_out_missing: int, early_leave: int, summary: array<string, int>, rows: array<int, array<string, mixed>>}
     */
    private function group(?int $id, string $name, ?string $homeroom, array $rows): array
    {
        $summary = array_fill_keys(['Hadir', 'Terlambat', 'Izin', 'Sakit', 'Alpa', 'Dikoreksi'], 0);
        $checkOutMissing = 0;
        $earlyLeave = 0;

        foreach ($rows as $row) {
            $summary[$row['status']] = ($summary[$row['status']] ?? 0) + 1;

            if (($row['check_out'] ?? '-') === '-' && in_array($row['status'], ['Hadir', 'Terlambat'], true)) {
                $checkOutMissing++;
            }

            if ($row['early_leave'] ?? false) {
                $earlyLeave++;
            }
        }

        return [
            'id' => $id,
            'name' => $name,
            'homeroom' => $homeroom,
            'total' => count($rows),
            'check_out_missing' => $checkOutMissing,
            'early_leave' => $earlyLeave,
            'summary' => $summary,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function rangeFor(string $period, Carbon $anchor, array $data = []): array
    {
        $monthName = fn (Carbon $date) => self::MONTHS[(int) $date->format('n')].' '.$date->format('Y');

        // Rentang bebas: Admin memilih sendiri tanggal/datetime awal dan akhir
        // (mis. 1 hari, 2 minggu, 1 bulan, atau beberapa tahun).
        if ($period === 'range') {
            $start = $this->parseBoundary((string) ($data['from'] ?? $data['to']), true);
            $end = $this->parseBoundary((string) ($data['to'] ?? $data['from']), false);

            if ($end->lt($start)) {
                [$start, $end] = [$end, $start];
            }

            return [
                $start,
                $end,
                'Rentang '.$this->rangeLabel($start, $end).$this->timeLabel($data),
            ];
        }

        // Semester mengikuti tahun ajaran: Ganjil Juli-Desember, Genap
        // Januari-Juni tahun berikutnya.
        if ($period === 'semester') {
            $settings = Setting::current();
            $academicYear = $data['academic_year'] ?? $settings->academic_year ?? '';
            $semester = $data['semester'] ?? $settings->semester ?? 'Ganjil';
            [$startYear, $endYear] = $this->academicYearBounds((string) $academicYear, $anchor);

            if ($semester === 'Genap') {
                $start = Carbon::create($endYear, 1, 1)->startOfDay();
                $end = Carbon::create($endYear, 6, 30)->endOfDay();
            } else {
                $start = Carbon::create($startYear, 7, 1)->startOfDay();
                $end = Carbon::create($startYear, 12, 31)->endOfDay();
            }

            return [
                $start,
                $end,
                'Semester '.$semester.' '.$startYear.'/'.$endYear.$this->timeLabel($data),
            ];
        }

        if ($period === 'day') {
            return [$anchor->copy()->startOfDay(), $anchor->copy()->endOfDay(), 'Harian '.$anchor->format('d').' '.$monthName($anchor).$this->timeLabel($data)];
        }

        if ($period === 'week') {
            $start = $anchor->copy()->startOfWeek(Carbon::MONDAY);
            $end = $anchor->copy()->endOfWeek(Carbon::SUNDAY);

            return [
                $start,
                $end,
                'Mingguan '.$start->format('d/m/Y').' - '.$end->format('d/m/Y').$this->timeLabel($data),
            ];
        }

        if ($period === 'year') {
            $start = $anchor->copy()->startOfYear();
            $end = $anchor->copy()->endOfYear();

            return [$start, $end, 'Tahunan '.$anchor->format('Y').$this->timeLabel($data)];
        }

        $monthsBack = match ($period) {
            '3months' => 2,
            '6months' => 5,
            default => 0,
        };

        $start = $anchor->copy()->startOfMonth()->subMonths($monthsBack);
        $end = $anchor->copy()->endOfMonth();

        $label = $monthsBack === 0
            ? 'Bulanan '.$monthName($start)
            : match ($period) {
                '3months' => '3 Bulan ('.$monthName($start).' - '.$monthName($end).')',
                default => '6 Bulan ('.$monthName($start).' - '.$monthName($end).')',
            };

        return [$start, $end, $label.$this->timeLabel($data)];
    }

    /**
     * Ubah "2026/2027" menjadi [2026, 2027]. Bila kosong/tidak valid,
     * tahun ajaran diturunkan dari tanggal acuan (Juli ke atas = tahun baru).
     *
     * @return array{0: int, 1: int}
     */
    private function academicYearBounds(string $value, Carbon $anchor): array
    {
        if (preg_match('/^(\d{4})\/(\d{4})$/', $value, $matches) === 1) {
            return [(int) $matches[1], (int) $matches[2]];
        }

        $startYear = $anchor->month >= 7 ? $anchor->year : $anchor->year - 1;

        return [$startYear, $startYear + 1];
    }

    /**
     * Ubah input tanggal/datetime dari Admin menjadi batas Carbon.
     * Tanggal saja dianggap satu hari penuh; datetime-local tanpa detik
     * dibulatkan ke akhir menit agar tap pada menit terakhir tetap ikut.
     */
    private function parseBoundary(string $value, bool $isStart): Carbon
    {
        $moment = Carbon::parse($value);

        if (strlen(trim($value)) <= 10) {
            return $isStart ? $moment->startOfDay() : $moment->endOfDay();
        }

        if (! $isStart && $moment->second === 0) {
            return $moment->copy()->addMinute()->subSecond();
        }

        return $moment;
    }

    /**
     * Label rentang: sembunyikan jam bila batasnya satu hari penuh.
     */
    private function rangeLabel(Carbon $start, Carbon $end): string
    {
        $formatDate = fn (Carbon $date): string => sprintf(
            '%d %s %s',
            (int) $date->format('j'),
            self::MONTHS[(int) $date->format('n')],
            $date->format('Y'),
        );

        $startOfDay = $start->format('H:i:s') === '00:00:00';
        $endOfDay = in_array($end->format('H:i:s'), ['23:59:59', '23:59:00'], true);

        if ($startOfDay && $endOfDay) {
            return $formatDate($start).' - '.$formatDate($end);
        }

        return $formatDate($start).' '.$start->format('H:i')
            .' - '.$formatDate($end).' '.$end->format('H:i');
    }

    /**
     * Keterangan filter jam pada label periode, mis. " • jam 06:30-07:15".
     *
     * @param  array<string, mixed>  $data
     */
    private function timeLabel(array $data): string
    {
        $from = $data['from_time'] ?? null;
        $to = $data['to_time'] ?? null;

        if ($from === null && $to === null) {
            return '';
        }

        return ' • jam '.($from ?: '00:00').' - '.($to ?: '23:59');
    }
}
