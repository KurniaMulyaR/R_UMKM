<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

class AccessMatrixController extends Controller
{
    private const ROLES = ['owner' => 'Pemilik', 'manager' => 'Manajer', 'cashier' => 'Kasir', 'warehouse' => 'Admin gudang'];

    private const MODULES = [
        'pos' => 'Kasir', 'sales' => 'Penjualan & retur', 'shifts' => 'Shift kasir', 'products' => 'Produk, varian & satuan', 'customers' => 'Anggota & piutang',
        'expenses' => 'Pengeluaran', 'purchasing' => 'Pembelian & supplier', 'purchase-returns' => 'Retur pembelian', 'stock-counts' => 'Opname stok', 'stock-transfers' => 'Transfer stok',
        'outlets' => 'Outlet', 'reconciliations' => 'Rekonsiliasi bank', 'journals' => 'Jurnal', 'ledger' => 'Buku besar', 'accounts' => 'Daftar akun', 'reports' => 'Laporan & tutup periode',
        'assets' => 'Aset usaha', 'staff' => 'Tim usaha', 'audit-logs' => 'Audit log', 'payment-gateway' => 'Pembayaran QRIS', 'documents' => 'Invoice & surat jalan', 'settings' => 'Pengaturan usaha',
    ];

    public function index(Request $request): View
    {
        $modules = [];
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            $middleware = $route->gatherMiddleware();
            if (! $name || ! in_array('tenant', $middleware, true)) {
                continue;
            }
            $allowed = array_keys(self::ROLES);
            foreach ($middleware as $entry) {
                if (is_string($entry) && str_starts_with($entry, 'role:')) {
                    $allowed = array_values(array_intersect($allowed, explode(',', substr($entry, 5))));
                }
            }
            $prefix = explode('.', $name)[0];
            $label = self::MODULES[$prefix] ?? null;
            if (! $label) {
                continue;
            }
            $modules[$label]['total'] = ($modules[$label]['total'] ?? 0) + 1;
            foreach ($allowed as $role) {
                $modules[$label]['roles'][$role] = ($modules[$label]['roles'][$role] ?? 0) + 1;
            }
        }
        ksort($modules);

        return view('access.matrix', ['modules' => $modules, 'roles' => self::ROLES]);
    }
}
