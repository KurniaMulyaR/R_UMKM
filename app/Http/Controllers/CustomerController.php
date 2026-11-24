<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $customers = DB::table('customers')->where('tenant_id', $tenant->id)->whereNull('deleted_at')
            ->orderBy('name')->paginate(20);
        return view('customers.index', compact('customers'));
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'], 'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:190'], 'address' => ['nullable', 'string', 'max:1000'],
        ]);
        DB::table('customers')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => $data['name'],
            'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null, 'address' => $data['address'] ?? null,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return back()->with('success', 'Pelanggan berhasil ditambahkan.');
    }
}
