<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attendance extends Model
{
    protected $fillable = [
        'student_id', 'rfid_card_id', 'device_id', 'schedule_id', 'attendance_date',
        'attendance_time', 'check_out_time', 'early_leave', 'status', 'auto_alpa', 'source', 'uid_snapshot', 'client_event_id', 'notes',
    ];

    protected $casts = [
        'attendance_date' => 'date:Y-m-d',
        'auto_alpa' => 'boolean',
        'early_leave' => 'boolean',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function rfidCard(): BelongsTo
    {
        return $this->belongsTo(RfidCard::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class);
    }
}
