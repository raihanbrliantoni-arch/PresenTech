<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemLog;
use Illuminate\Http\Request;

class SystemLogController extends Controller
{
    public function index(Request $request)
    {
        $q = SystemLog::with(['user', 'device'])->latest();
        if ($request->filled('type')) {
            $q->where('type', $request->type);
        } if ($request->filled('search')) {
            $q->where(function ($x) use ($request) {
                $x->where('action', 'like', '%'.$request->search.'%')->orWhere('message', 'like', '%'.$request->search.'%');
            });
        }

        return response()->json($q->paginate(min(max((int) $request->input('per_page', 50), 1), 1000)));
    }

    public function store(Request $request)
    {
        $d = $request->validate(['device_id' => ['nullable', 'integer', 'exists:devices,id'], 'type' => ['required', 'in:system,device,rfid,settings,api'], 'action' => ['required', 'string', 'max:150'], 'message' => ['nullable', 'string'], 'metadata' => ['nullable', 'array']]);
        $d['user_id'] = $request->user()?->id;
        $d['ip_address'] = $request->ip();

        return response()->json(SystemLog::create($d), 201);
    }

    public function destroy(SystemLog $systemLog)
    {
        $systemLog->delete();

        return response()->json(['message' => 'Log dihapus.']);
    }
}
