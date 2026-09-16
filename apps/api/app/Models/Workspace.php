<?php

namespace App\Models;

use App\Enums\ProjectType;
use App\Enums\WorkspaceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'project_id',
        'name',
        'type',
        'openwrt_revision',
        'status',
        'path',
        'target',
        'subtarget',
        'profile',
        'git_remote_url',
        'git_remote_branch',
        'git_token',
    ];

    protected function casts(): array
    {
        return [
            'type' => ProjectType::class,
            'status' => WorkspaceStatus::class,
            'git_token' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function aiSessions(): HasMany
    {
        return $this->hasMany(AiSession::class);
    }

    public function builds(): HasMany
    {
        return $this->hasMany(Build::class);
    }

    public function configSnapshots(): HasMany
    {
        return $this->hasMany(ConfigSnapshot::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', WorkspaceStatus::Archived);
    }
}
