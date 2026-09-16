<?php

namespace App\Http\Concerns;

use App\Enums\UserRole;
use Illuminate\Http\Request;

trait AuthorizesAdmin
{
    protected function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === UserRole::Admin, 403);
    }
}
