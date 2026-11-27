<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $isAdmin = $request->user() && DB::table('users')->where('id', $request->user()->id)
            ->where('is_platform_admin', true)->where('status', 'active')->exists();
        abort_unless($isAdmin, 403, 'Halaman ini hanya untuk pengelola platform.');

        return $next($request);
    }
}
