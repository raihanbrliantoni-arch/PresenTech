<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RfidCard extends Model
{
    protected $fillable = ['student_id', 'uid', 'label', 'status', 'registered_at', 'last_used_at'];

    protected $casts = ['registered_at' => 'datetime', 'last_used_at' => 'datetime'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }
}
