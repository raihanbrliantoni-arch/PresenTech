<?php

namespace App\Support;

use App\Models\SystemLog;
use Illuminate\Http\Request;

final class Audit
{
    public static function log(Request $request, string $type, string $action, string $message, array $metadata = []): void
    {
        SystemLog::create(['user_id' => $request->user()?->id, 'device_id' => $metadata['device_id'] ?? null, 'type' => $type, 'action' => $action, 'message' => $message, 'ip_address' => $request->ip(), 'metadata' => $metadata]);
    }
}
