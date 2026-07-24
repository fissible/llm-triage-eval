<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvalResult extends Model
{
    protected $guarded = [];

    protected $casts = [
        'correct' => 'boolean',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(EvalRun::class, 'eval_run_id');
    }
}
