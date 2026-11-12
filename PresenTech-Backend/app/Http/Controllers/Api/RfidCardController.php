<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\RfidCard;
use App\Support\Audit;
use App\Support\RfidUid;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RfidCardController extends Controller
{
    public function index(Request $request)
    {
        $query = RfidCard::with('student.schoolClass');

        if ($request->filled('uid')) {
            $query->where('uid', RfidUid::normalize($request->string('uid')->toString()));
        }

        if ($request->filled('student_id')) {
            $query->where('student_id', $request->integer('student_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json($query->latest()->paginate(min(max((int) $request->input('per_page', 20), 1), 1000)));
    }

    public function show(RfidCard $rfidCard)
    {
        return response()->json($rfidCard->load('student.schoolClass'));
    }

    public function store(Request $request)
    {
        if ($request->filled('uid')) {
            $request->merge(['uid' => RfidUid::normalize($request->input('uid'))]);
        }

        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'uid' => ['required', 'string', 'max:64', 'unique:rfid_cards,uid'],
            'label' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['Aktif', 'Nonaktif'])],
            'registered_at' => ['nullable', 'date'],
        ]);

        $data['registered_at'] = $data['registered_at'] ?? now();

        $card = RfidCard::create($data);
        $card->load('student');
        if ($card->student?->user_id) {
            Notification::create([
                'recipient_user_id' => $card->student->user_id,
                'recipient_role' => 'siswa',
                'type' => 'rfid',
                'title' => 'Kartu RFID terdaftar',
                'message' => 'Kartu RFID kamu berhasil didaftarkan ke akun PresenTech.',
                'expires_at' => now()->addHours(24),
            ]);
        }
        Audit::log($request, 'rfid', 'rfid.created', 'Kartu RFID didaftarkan: '.$card->uid, ['rfid_card_id' => $card->id]);

        return response()->json($card->load('student.schoolClass'), 201);
    }

    public function update(Request $request, RfidCard $rfidCard)
    {
        if ($request->filled('uid')) {
            $request->merge(['uid' => RfidUid::normalize($request->input('uid'))]);
        }

        $data = $request->validate([
            'student_id' => ['sometimes', 'integer', 'exists:students,id'],
            'uid' => ['sometimes', 'string', 'max:64', Rule::unique('rfid_cards', 'uid')->ignore($rfidCard->id)],
            'label' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['Aktif', 'Nonaktif'])],
            'registered_at' => ['nullable', 'date'],
        ]);

        $rfidCard->update($data);
        Audit::log($request, 'rfid', 'rfid.updated', 'Kartu RFID diubah: '.$rfidCard->uid, ['rfid_card_id' => $rfidCard->id]);

        return response()->json($rfidCard->fresh()->load('student.schoolClass'));
    }

    public function destroy(Request $request, RfidCard $rfidCard)
    {
        Audit::log($request, 'rfid', 'rfid.deleted', 'Kartu RFID dihapus: '.$rfidCard->uid, ['rfid_card_id' => $rfidCard->id]);
        $rfidCard->delete();

        return response()->json(['message' => 'Kartu RFID berhasil dihapus.']);
    }
}
