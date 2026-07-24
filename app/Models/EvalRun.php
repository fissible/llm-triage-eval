<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvalRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'fully_reviewed' => 'boolean',
        'per_category' => 'array',
        'confusion' => 'array',
        'llm_accuracy' => 'float',
        'baseline_accuracy' => 'float',
        'cost_usd' => 'decimal:4',
        'ran_at' => 'datetime',
    ];

    public function results(): HasMany
    {
        return $this->hasMany(EvalResult::class);
    }

    public function failures(): HasMany
    {
        return $this->results()->where('correct', false);
    }
}
