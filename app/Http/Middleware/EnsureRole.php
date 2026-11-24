<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$allowedRoles): Response
    {
        $role = $request->attributes->get('role_key');
        abort_unless($role && in_array($role, $allowedRoles, true), 403, 'Akses ini belum tersedia untuk peran akunmu.');
        return $next($request);
    }
}
