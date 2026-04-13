<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncLock extends Model
{
    protected $fillable = [
        'source',
        'issue_ref',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
