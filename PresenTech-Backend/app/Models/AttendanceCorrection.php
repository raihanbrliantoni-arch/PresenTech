<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceCorrection extends Model
{
    protected $fillable = [
        'attendance_id', 'student_id', 'requested_by', 'reviewed_by', 'attendance_date',
        'expected_time', 'actual_time', 'reason', 'review_note', 'status', 'reviewed_at',
    ];

    protected $casts = ['attendance_date' => 'date', 'reviewed_at' => 'datetime'];

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
