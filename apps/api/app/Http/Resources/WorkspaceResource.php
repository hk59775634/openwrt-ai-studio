<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Workspace
 */
class WorkspaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'openwrt_revision' => $this->openwrt_revision,
            'target' => $this->target,
            'subtarget' => $this->subtarget,
            'profile' => $this->profile,
            'status' => $this->status->value,
            'path' => $this->path,
            'project_id' => $this->project_id,
            'git_remote_url' => $this->git_remote_url,
            'git_remote_branch' => $this->git_remote_branch,
            'git_token_set' => filled($this->git_token),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
