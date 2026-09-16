<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuildLog extends Model
{
    protected $fillable = [
        'build_id',
        'sequence',
        'stream',
        'content',
    ];

    public function build(): BelongsTo
    {
        return $this->belongsTo(Build::class);
    }
}
