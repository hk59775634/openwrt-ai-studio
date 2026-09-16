<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiOperation extends Model
{
    protected $fillable = [
        'ai_session_id',
        'tool',
        'input',
        'output',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiSession::class, 'ai_session_id');
    }
}
