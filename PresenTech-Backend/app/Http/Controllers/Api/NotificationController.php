<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\NotificationRead;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        // Bersihkan notifikasi kadaluarsa maksimal sekali per 15 menit,
        // bukan pada setiap request.
        if (Cache::add('presentech:notification-purge', true, now()->addMinutes(15))) {
            Notification::where('expires_at', '<=', now())->delete();
        }

        $query = Notification::query();
        $user = $request->user();

        if ($user) {
            $userClass = $user->student?->schoolClass?->name;
            $query->where(function ($q) use ($user, $userClass) {
                $q->where('recipient_user_id', $user->id)
                    ->orWhere(function ($q2) use ($user, $userClass) {
                        $q2->whereNull('recipient_user_id')
                            ->where('recipient_role', $user->role)
                            ->where(function ($q3) use ($userClass) {
                                $q3->whereNull('recipient_class');
                                if ($userClass) {
                                    $q3->orWhere('recipient_class', $userClass);
                                }
                            })
                            ->where(function ($q3) use ($user) {
                                $q3->whereNull('recipient_nis')
                                    ->orWhere(function ($q4) use ($user) {
                                        $nis = $user->student?->nis;
                                        if ($nis) {
                                            $q4->where('recipient_nis', $nis);
                                        } else {
                                            $q4->whereRaw('1 = 0');
                                        }
                                    });
                            });
                    });
            });

            // Filter penerima hanya untuk Admin (mencegah probing penerima dari role lain).
            if ($user->role === 'admin') {
                if ($request->filled('recipient_user_id')) {
                    $query->where('recipient_user_id', $request->integer('recipient_user_id'));
                }
                if ($request->filled('recipient_role')) {
                    $query->where('recipient_role', $request->string('recipient_role'));
                }
                if ($request->filled('recipient_class')) {
                    $query->where('recipient_class', $request->string('recipient_class'));
                }
                if ($request->filled('recipient_nis')) {
                    $query->where('recipient_nis', $request->string('recipient_nis'));
                }
            }
        }

        $paginator = $query->active()->latest()->paginate(min(max((int) $request->input('per_page', 20), 1), 1000));

        // Status "sudah dibaca" untuk notifikasi broadcast dihitung per user
        // (pivot notification_reads), sehingga tidak lagi dibagi-bagi antar role.
        if ($user) {
            $ids = $paginator->getCollection()->pluck('id');
            $readIds = $ids->isNotEmpty()
                ? DB::table('notification_reads')->where('user_id', $user->id)->whereIn('notification_id', $ids)->pluck('notification_id')
                : collect();

            $paginator->getCollection()->transform(function (Notification $n) use ($readIds) {
                if ($n->recipient_user_id === null && $readIds->contains($n->id)) {
                    $n->is_read = true;
                    $n->read_at = $n->read_at ?: now();
                }

                return $n;
            });
        }

        return response()->json($paginator);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()?->role === 'admin', 403);
        $data = $request->validate([
            'recipient_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'recipient_role' => ['nullable', 'in:admin,guru,siswa'],
            'recipient_class' => ['nullable', 'string', 'max:50'],
            'recipient_nis' => ['nullable', 'string', 'max:30'],
            'type' => ['nullable', 'string', 'max:50'],
            'title' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string'],
            'icon' => ['nullable', 'string', 'max:50'],
            'expires_at' => ['nullable', 'date'],
        ]);

        // Pengirim selalu user yang sedang login; tidak bisa dipalsukan.
        $data['sender_user_id'] = $request->user()->id;
        $data['is_read'] = false;
        $data['expires_at'] = $data['expires_at'] ?? now()->addHours(24);

        $notification = Notification::create($data);

        return response()->json($notification, 201);
    }

    public function show(Request $request, Notification $notification)
    {
        abort_unless($this->canAccess($request->user(), $notification), 403, 'Notifikasi ini bukan milikmu.');

        return response()->json($notification);
    }

    public function markRead(Request $request, Notification $notification)
    {
        $user = $request->user();
        abort_unless($this->canAccess($user, $notification), 403, 'Notifikasi ini bukan milikmu.');

        if ($notification->recipient_user_id !== null) {
            // Notifikasi langsung: tandai kolom milik penerima.
            $notification->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        } elseif ($user) {
            // Notifikasi broadcast: catat status baca khusus user ini.
            NotificationRead::firstOrCreate(
                ['notification_id' => $notification->id, 'user_id' => $user->id],
                ['read_at' => now()]
            );
        }

        return response()->json($notification);
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Semua notifikasi ditandai sudah dibaca.']);
        }

        // 1) Notifikasi langsung milik user.
        Notification::query()
            ->where('recipient_user_id', $user->id)
            ->active()
            ->update(['is_read' => true, 'read_at' => now()]);

        // 2) Notifikasi broadcast yang relevan untuk user ini (role, kelas, NIS).
        $userClass = $user->student?->schoolClass?->name;
        $userNis = $user->student?->nis;

        $broadcastIds = Notification::query()
            ->active()
            ->whereNull('recipient_user_id')
            ->where('recipient_role', $user->role)
            ->where(function ($q) use ($userClass) {
                $q->whereNull('recipient_class');
                if ($userClass) {
                    $q->orWhere('recipient_class', $userClass);
                }
            })
            ->where(function ($q) use ($userNis) {
                $q->whereNull('recipient_nis');
                if ($userNis) {
                    $q->orWhere('recipient_nis', $userNis);
                } else {
                    $q->whereRaw('recipient_nis IS NULL');
                }
            })
            ->pluck('id');

        if ($broadcastIds->isNotEmpty()) {
            $now = now();
            $rows = $broadcastIds
                ->map(fn ($id) => ['notification_id' => $id, 'user_id' => $user->id, 'read_at' => $now])
                ->all();
            DB::table('notification_reads')->insertOrIgnore($rows);
        }

        return response()->json(['message' => 'Semua notifikasi ditandai sudah dibaca.']);
    }

    public function destroy(Request $request, Notification $notification)
    {
        $user = $request->user();

        if ($notification->recipient_user_id !== null) {
            // Notifikasi langsung: hanya penerima atau Admin yang boleh hapus.
            abort_unless($notification->recipient_user_id === $user?->id || $user?->role === 'admin', 403, 'Notifikasi ini bukan milikmu.');
        } else {
            // Notifikasi broadcast: hanya Admin yang boleh hapus.
            abort_unless($user?->role === 'admin', 403, 'Notifikasi broadcast hanya bisa dihapus Admin.');
        }

        $notification->delete();

        return response()->json(['message' => 'Notifikasi berhasil dihapus.']);
    }

    /**
     * Notifikasi boleh diakses bila: ditujukan langsung ke user,
     * atau broadcast yang cocok dengan role/kelas/NIS user.
     */
    private function canAccess(?User $user, Notification $n): bool
    {
        if (! $user) {
            return false;
        }
        if ($n->recipient_user_id !== null) {
            return $n->recipient_user_id === $user->id;
        }
        if ($user->role === 'admin') {
            return true;
        }
        if ($n->recipient_role && $n->recipient_role !== $user->role) {
            return false;
        }

        $userClass = $user->student?->schoolClass?->name;
        if ($n->recipient_class && (! $userClass || $userClass !== $n->recipient_class)) {
            return false;
        }

        $userNis = $user->student?->nis;
        if ($n->recipient_nis && (! $userNis || $userNis !== $n->recipient_nis)) {
            return false;
        }

        return true;
    }
}
