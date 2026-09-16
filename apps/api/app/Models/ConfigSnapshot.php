<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfigSnapshot extends Model
{
    protected $fillable = [
        'workspace_id',
        'user_id',
        'name',
        'target',
        'subtarget',
        'profile',
        'openwrt_revision',
        'content',
        'sha256',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
