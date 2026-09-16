<?php

namespace App\Models;

use App\Enums\BuildStatus;
use App\Enums\BuildType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Build extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'user_id',
        'type',
        'status',
        'package_name',
        'git_commit',
        'openwrt_revision',
        'architecture',
        'target',
        'command',
        'cpu_limit',
        'memory_limit',
        'timeout',
        'artifact_manifest',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => BuildType::class,
            'status' => BuildStatus::class,
            'artifact_manifest' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(BuildLog::class)->orderBy('sequence');
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(Artifact::class);
    }
}
