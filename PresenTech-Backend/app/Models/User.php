<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'username', 'phone', 'avatar', 'password',
    ];

    // 'role' sengaja tidak mass-assignable agar tidak bisa dinaikkan
    // lewat request; role hanya diisi lewat kode terpercaya (seeder, register).

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function hasAnyRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function student()
    {
        return $this->hasOne(Student::class);
    }

    public function teacher()
    {
        return $this->hasOne(Teacher::class);
    }

    public function sentNotifications()
    {
        return $this->hasMany(Notification::class, 'sender_user_id');
    }

    public function receivedNotifications()
    {
        return $this->hasMany(Notification::class, 'recipient_user_id');
    }

    public function requestedCorrections()
    {
        return $this->hasMany(AttendanceCorrection::class, 'requested_by');
    }

    public function reviewedCorrections()
    {
        return $this->hasMany(AttendanceCorrection::class, 'reviewed_by');
    }

    public function systemLogs()
    {
        return $this->hasMany(SystemLog::class);
    }
}
