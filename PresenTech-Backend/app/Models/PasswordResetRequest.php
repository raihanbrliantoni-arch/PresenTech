<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordResetRequest extends Model
{
    /**
     * Hash token klaim tidak pernah perlu dikirim ke client, termasuk
     * panel Admin; token asli hanya dikembalikan sekali saat pengajuan.
     */
    protected $hidden = [
        'claim_token',
    ];

    protected $fillable = [
        'user_id',
        'identifier',
        'role',
        'status',
        'claim_token',
        'review_note',
        'reviewed_by',
        'reviewed_at',
        'expires_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
