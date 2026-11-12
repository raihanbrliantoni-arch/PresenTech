<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HolidayController extends Controller
{
    /**
     * Daftar hari libur, bisa disaring per tahun/bulan dan dicari namanya.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $query = Holiday::query();

        if (! empty($data['year'])) {
            $query->whereYear('date', $data['year']);
        }

        if (! empty($data['month'])) {
            $query->whereMonth('date', $data['month']);
        }

        if (! empty($data['search'])) {
            $query->where('name', 'like', '%'.$data['search'].'%');
        }

        return response()->json(
            $query->orderBy('date')
                ->paginate(min(max((int) $request->input('per_page', 100), 1), 500))
        );
    }

    /**
     * Status hari libur untuk tanggal hari ini. Dipakai dashboard Admin,
     * Guru, dan Siswa agar tidak menampilkan 0% kehadiran pada hari libur.
     */
    public function today(): JsonResponse
    {
        $holiday = Holiday::forDate(now());

        return response()->json([
            'date' => now()->toDateString(),
            'is_holiday' => $holiday !== null,
            'holiday' => $holiday === null ? null : $this->present($holiday),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $holiday = Holiday::create($this->validated($request));

        Audit::log(
            $request,
            'system',
            'holiday.created',
            'Hari libur ditambahkan: '.$holiday->name.' ('.$holiday->date->toDateString().')',
            ['holiday_id' => $holiday->id],
        );

        return response()->json($this->present($holiday), 201);
    }

    public function update(Request $request, Holiday $holiday): JsonResponse
    {
        $holiday->update($this->validated($request, $holiday));

        Audit::log(
            $request,
            'system',
            'holiday.updated',
            'Hari libur diubah: '.$holiday->name.' ('.$holiday->date->toDateString().')',
            ['holiday_id' => $holiday->id],
        );

        return response()->json($this->present($holiday->fresh()));
    }

    public function destroy(Request $request, Holiday $holiday): JsonResponse
    {
        $label = $holiday->name.' ('.$holiday->date->toDateString().')';
        $holiday->delete();

        Audit::log($request, 'system', 'holiday.deleted', 'Hari libur dihapus: '.$label);

        return response()->json(['message' => 'Hari libur berhasil dihapus.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Holiday $holiday = null): array
    {
        $data = $request->validate([
            'date' => [
                'required',
                'date_format:Y-m-d',
                Rule::unique('holidays', 'date')->ignore($holiday?->id),
            ],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['nullable', Rule::in(Holiday::TYPES)],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $data['name'] = trim($data['name']);
        $data['type'] = $data['type'] ?? 'nasional';

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Holiday $holiday): array
    {
        return [
            'id' => $holiday->id,
            'date' => $holiday->date->toDateString(),
            'name' => $holiday->name,
            'type' => $holiday->type,
            'type_label' => Holiday::TYPE_LABELS[$holiday->type] ?? $holiday->type,
            'description' => $holiday->description,
            'created_at' => $holiday->created_at,
            'updated_at' => $holiday->updated_at,
        ];
    }
}
