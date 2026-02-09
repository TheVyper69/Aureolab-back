<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// ✅ ESTE IMPORT ES EL QUE TE FALTA
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    // Si tu tabla users NO tiene estos campos default, ajusta $fillable según tu BD
    protected $fillable = [
        'role_id',
        'optica_id',
        'name',
        'email',
        'phone',
        'password',
        'active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function role()
    {
        return $this->belongsTo(\App\Models\Role::class);
    }

}