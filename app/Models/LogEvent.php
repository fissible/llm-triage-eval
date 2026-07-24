<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogEvent extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'error_detail' => 'array',
        'occurred_at' => 'datetime',
    ];
}
