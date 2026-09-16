<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMessage extends Model
{
    protected $fillable = [
        'ai_session_id',
        'role',
        'content',
        'tool_calls',
        'tool_call_id',
    ];

    protected function casts(): array
    {
        return [
            'tool_calls' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiSession::class, 'ai_session_id');
    }
}
