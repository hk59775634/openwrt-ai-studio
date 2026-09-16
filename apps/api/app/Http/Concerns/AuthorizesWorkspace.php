<?php

namespace App\Http\Concerns;

use App\Models\Workspace;
use Illuminate\Http\Request;

trait AuthorizesWorkspace
{
    protected function authorizeWorkspace(Request $request, Workspace $workspace): void
    {
        abort_unless($workspace->user_id === $request->user()?->id, 403);
    }
}
