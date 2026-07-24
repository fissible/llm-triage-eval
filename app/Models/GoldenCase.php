<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoldenCase extends Model
{
    protected $guarded = [];

    protected $casts = [
        'reviewed' => 'boolean',
        'input' => 'array',
        'occurred_at' => 'datetime',
    ];

    /** True when the human label diverged from the rule-based weak label. */
    public function getCorrectedAttribute(): bool
    {
        return $this->gold_label !== $this->weak_label;
    }
}
