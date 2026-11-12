<?php

namespace Tests\Feature;

use App\Models\SystemLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SystemLogPruneTest extends TestCase
{
    use RefreshDatabase;

    private function makeLog(string $action, int $daysAgo): SystemLog
    {
        $log = SystemLog::create([
            'type' => 'system',
            'action' => $action,
            'message' => 'Log uji retensi',
        ]);

        $log->forceFill(['created_at' => Carbon::now()->subDays($daysAgo)])->save();

        return $log;
    }

    public function test_log_lebih_lama_dari_30_hari_dihapus(): void
    {
        $old = $this->makeLog('system.old', 31);
        $recent = $this->makeLog('system.recent', 29);

        $this->artisan('logs:prune')->assertSuccessful();

        $this->assertDatabaseMissing('system_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('system_logs', ['id' => $recent->id]);
    }

    public function test_opsi_hari_bisa_mengubah_masa_retensi(): void
    {
        $log = $this->makeLog('system.custom', 10);

        $this->artisan('logs:prune', ['--days' => 7])->assertSuccessful();

        $this->assertDatabaseMissing('system_logs', ['id' => $log->id]);
    }

    public function test_log_baru_tidak_terhapus(): void
    {
        $log = $this->makeLog('system.new', 0);

        $this->artisan('logs:prune')->assertSuccessful();

        $this->assertDatabaseHas('system_logs', ['id' => $log->id]);
    }
}
