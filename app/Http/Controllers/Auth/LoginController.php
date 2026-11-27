<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View|RedirectResponse
    {
        if (Auth::check()) return redirect()->route('dashboard');
        if (DB::table('users')->count() === 0) return redirect()->route('setup');
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['identity' => ['required', 'string', 'max:190'], 'password' => ['required', 'string']]);
        $field = filter_var($data['identity'], FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        if (! Auth::attempt([$field => $data['identity'], 'password' => $data['password'], 'status' => 'active'], $request->boolean('remember'))) {
            return back()->withErrors(['identity' => 'Nomor HP/email atau kata sandi belum cocok.'])->onlyInput('identity');
        }
        $request->session()->regenerate();
        DB::table('users')->where('id', Auth::id())->update(['last_login_at' => now(), 'updated_at' => now()]);
        $isPlatformAdmin = DB::table('users')->where('id', Auth::id())->where('is_platform_admin', true)->exists();
        if ($isPlatformAdmin && ! DB::table('tenant_user')->where('user_id', Auth::id())->where('status', 'active')->exists()) {
            return redirect()->route('platform.dashboard');
        }

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
