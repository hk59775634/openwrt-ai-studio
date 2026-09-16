<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Artifact extends Model
{
    protected $fillable = [
        'build_id',
        'name',
        'object_key',
        'sha256',
        'size',
    ];

    public function build(): BelongsTo
    {
        return $this->belongsTo(Build::class);
    }
}
