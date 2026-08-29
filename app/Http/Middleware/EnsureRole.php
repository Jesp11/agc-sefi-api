<?php

namespace App\Http\Middleware;

use App\Support\RoleHelper;
use Closure;
use Illuminate\Http\Request;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = auth()->user();

        if (!$user || !$user->role) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        if (!in_array($user->role->nombre, RoleHelper::expands($roles), true)) {
            return response()->json(['message' => 'Acceso denegado para este rol.'], 403);
        }

        return $next($request);
    }
}
