<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SalesController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = $request->attributes->get('tenant'); $outlet = $request->attributes->get('outlet');
        $query = DB::table('sales')->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->join('users', 'users.id', '=', 'sales.cashier_user_id')
            ->where('sales.tenant_id', $tenant->id)->where('sales.outlet_id', $outlet->id);
        if ($request->filled('q')) $query->where(function ($q) use ($request) {
            $q->where('sales.sale_number', 'like', '%'.$request->string('q').'%')->orWhere('customers.name', 'like', '%'.$request->string('q').'%');
        });
        if ($request->filled('date')) $query->whereDate('sales.sold_at', $request->input('date'));
        $sales = $query->select('sales.*', 'customers.name as customer_name', 'users.name as cashier_name')->orderByDesc('sales.sold_at')->paginate(20)->withQueryString();
        return view('sales.index', compact('sales'));
    }

    public function show(Request $request, string $id): View
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $sale = DB::table('sales')->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->join('users', 'users.id', '=', 'sales.cashier_user_id')->where('sales.tenant_id', $tenant->id)->where('sales.outlet_id', $outlet->id)->where('sales.id', $id)
            ->select('sales.*', 'customers.name as customer_name', 'customers.phone as customer_phone', 'users.name as cashier_name')->first();
        abort_unless($sale, 404);
        $items = DB::table('sale_items')->where('tenant_id', $tenant->id)->where('sale_id', $sale->id)->get();
        $payments = DB::table('payment_allocations')->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payment_allocations.tenant_id', $tenant->id)->where('payment_allocations.sale_id', $sale->id)
            ->select('payments.method', 'payments.status', 'payment_allocations.amount', 'payments.received_at')->get();
        $journal = DB::table('journal_entries')->where('tenant_id', $tenant->id)->where('source_type', 'sale')->where('source_id', $sale->id)->first();
        $isOwner = DB::table('tenant_user_roles')->join('roles', 'roles.id', '=', 'tenant_user_roles.role_id')
            ->where('tenant_user_roles.tenant_user_id', $request->attributes->get('tenant_membership')->id)
            ->where('roles.key', 'owner')->exists();
        return view('sales.show', compact('sale', 'items', 'payments', 'journal', 'isOwner'));
    }

    public function void(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant'); $outlet = $request->attributes->get('outlet');
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'password' => ['required', 'string'], 'confirm_void' => ['accepted'],
        ]);
        $membership = $request->attributes->get('tenant_membership');
        $isOwner = DB::table('tenant_user_roles')->join('roles', 'roles.id', '=', 'tenant_user_roles.role_id')
            ->where('tenant_user_roles.tenant_user_id', $membership->id)->where('roles.key', 'owner')->exists();
        abort_unless($isOwner && Hash::check($data['password'], auth()->user()->password), 403, 'Aksi ini hanya untuk pemilik dan perlu konfirmasi kata sandi.');

        DB::transaction(function () use ($tenant, $outlet, $id, $data) {
            $sale = DB::table('sales')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($sale && $sale->status === 'completed', 422, 'Transaksi ini tidak dapat dibatalkan.');
            AccountingPeriod::assertOpen($tenant->id, \Illuminate\Support\Carbon::parse($sale->sold_at, $tenant->timezone));
            $now = now($tenant->timezone);

            $movements = DB::table('stock_movements')->where('tenant_id', $tenant->id)->where('source_type', 'sale')->where('source_id', $sale->id)->get();
            foreach ($movements as $movement) {
                $balance = DB::table('stock_balances')->where('tenant_id', $tenant->id)->where('outlet_id', $movement->outlet_id)
                    ->where('product_variant_id', $movement->product_variant_id)->lockForUpdate()->first();
                if (! $balance) continue;
                DB::table('stock_movements')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'outlet_id' => $movement->outlet_id,
                    'product_variant_id' => $movement->product_variant_id, 'occurred_at' => $now, 'movement_type' => 'sale_void',
                    'quantity_delta' => abs((float) $movement->quantity_delta), 'unit_cost' => $movement->unit_cost,
                    'value_delta' => abs((float) $movement->value_delta), 'source_type' => 'sale_void', 'source_id' => $sale->id,
                    'source_line_id' => $movement->source_line_id, 'idempotency_key' => 'sale-void:'.$sale->id.':'.$movement->product_variant_id,
                    'created_by_user_id' => auth()->id(), 'reason' => $data['reason'], 'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('stock_balances')->where('id', $balance->id)->update([
                    'quantity_on_hand' => (float) $balance->quantity_on_hand + abs((float) $movement->quantity_delta),
                    'inventory_value' => (float) $balance->inventory_value + abs((float) $movement->value_delta),
                    'version' => $balance->version + 1, 'updated_at' => $now,
                ]);
            }

            $original = DB::table('journal_entries')->where('tenant_id', $tenant->id)->where('source_type', 'sale')->where('source_id', $sale->id)->first();
            if ($original && $original->status === 'posted') {
                $reversalId = (string) Str::uuid();
                DB::table('journal_entries')->insert([
                    'id' => $reversalId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id,
                    'entry_number' => 'RV-'.$sale->sale_number, 'entry_date' => $now->toDateString(), 'status' => 'posted',
                    'description' => 'Pembatalan '.$sale->sale_number, 'source_type' => 'sale_void', 'source_id' => $sale->id,
                    'idempotency_key' => 'sale:'.$sale->id.':reversal', 'posted_at' => $now,
                    'posted_by_user_id' => auth()->id(), 'created_by_user_id' => auth()->id(), 'reversal_of_entry_id' => $original->id,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $lines = DB::table('journal_lines')->where('journal_entry_id', $original->id)->get();
                foreach ($lines as $line) DB::table('journal_lines')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'journal_entry_id' => $reversalId,
                    'account_id' => $line->account_id, 'outlet_id' => $line->outlet_id,
                    'customer_id' => $line->customer_id, 'supplier_id' => $line->supplier_id,
                    'product_variant_id' => $line->product_variant_id, 'description' => 'Pembalik: '.$line->description,
                    'debit' => $line->credit, 'credit' => $line->debit, 'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('journal_entries')->where('id', $original->id)->update(['status' => 'reversed', 'updated_at' => $now]);
            }

            $paymentIds = DB::table('payment_allocations')->where('tenant_id', $tenant->id)->where('sale_id', $sale->id)->pluck('payment_id');

            // Penerimaan kasbon yang masuk setelah penjualan juga dibalik; kalau tidak, piutang 1200 jadi bersaldo kredit
            // dan kas tetap tercatat padahal transaksinya sudah dibatalkan.
            $receiptEntries = DB::table('journal_entries')->where('tenant_id', $tenant->id)->where('source_type', 'payment')
                ->whereIn('source_id', $paymentIds)->where('status', 'posted')->get();
            foreach ($receiptEntries as $receipt) {
                $receiptReversalId = (string) Str::uuid();
                DB::table('journal_entries')->insert([
                    'id' => $receiptReversalId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id,
                    'entry_number' => 'RV-'.$receipt->entry_number, 'entry_date' => $now->toDateString(), 'status' => 'posted',
                    'description' => 'Pembatalan '.$receipt->description, 'source_type' => 'payment_void', 'source_id' => $receipt->source_id,
                    'idempotency_key' => 'payment:'.$receipt->source_id.':reversal', 'posted_at' => $now,
                    'posted_by_user_id' => auth()->id(), 'created_by_user_id' => auth()->id(), 'reversal_of_entry_id' => $receipt->id,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                foreach (DB::table('journal_lines')->where('journal_entry_id', $receipt->id)->get() as $line) {
                    DB::table('journal_lines')->insert([
                        'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'journal_entry_id' => $receiptReversalId,
                        'account_id' => $line->account_id, 'outlet_id' => $line->outlet_id, 'customer_id' => $line->customer_id,
                        'supplier_id' => $line->supplier_id, 'product_variant_id' => $line->product_variant_id,
                        'description' => 'Pembalik: '.$line->description, 'debit' => $line->credit, 'credit' => $line->debit,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
                DB::table('journal_entries')->where('id', $receipt->id)->update(['status' => 'reversed', 'updated_at' => $now]);
            }
            DB::table('payments')->whereIn('id', $paymentIds)->whereIn('status', ['confirmed', 'pending'])->update(['status' => 'reversed', 'updated_at' => $now]);
            DB::table('sales')->where('id', $sale->id)->update([
                'status' => 'voided', 'paid_total' => 0, 'voided_at' => $now, 'voided_by_user_id' => auth()->id(),
                'void_reason' => $data['reason'], 'updated_at' => $now,
            ]);
            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id,
                'actor_user_id' => auth()->id(), 'action' => 'sale.voided', 'subject_type' => 'sale', 'subject_id' => $sale->id,
                'before' => json_encode(['status' => 'completed']), 'after' => json_encode(['status' => 'voided', 'reason' => $data['reason']]),
                'reason' => $data['reason'], 'occurred_at' => $now, 'created_at' => $now,
            ]);
        }, attempts: 3);

        return redirect()->route('sales.show', $id)->with('success', 'Transaksi dibatalkan dan stok/jurnal sudah dibalik. Jika pembayaran digital sudah settlement, refund tetap perlu diproses di penyedia.');
    }
    public function collectPayment(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $data = $request->validate([
            'method' => ['required', 'in:cash,bank_transfer,qris_external'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
        ]);

        DB::transaction(function () use ($tenant, $outlet, $id, $data) {
            $sale = DB::table('sales')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
                ->where('id', $id)->lockForUpdate()->first();
            abort_unless($sale && in_array($sale->status, ['completed', 'partially_returned'], true), 404);
            $amount = round((float) $data['amount'], 2);
            $balanceDue = round((float) $sale->grand_total - (float) $sale->paid_total, 2);
            if ($amount > $balanceDue) throw ValidationException::withMessages(['amount' => 'Nominal melebihi sisa kasbon.']);

            $shift = DB::table('cash_shifts')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
                ->where('cashier_user_id', auth()->id())->where('status', 'open')->lockForUpdate()->first();
            if (! $shift) throw ValidationException::withMessages(['shift' => 'Buka shift sebelum menerima pembayaran.']);
            $accountType = match ($data['method']) {
                'cash' => 'cash', 'bank_transfer' => 'bank', 'qris_external' => 'qris_clearing',
            };
            $cashAccount = DB::table('cash_accounts')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
                ->where('type', $accountType)->where('is_active', true)->first();
            if (! $cashAccount) throw ValidationException::withMessages(['method' => 'Akun metode pembayaran belum disiapkan.']);

            $sequence = DB::table('payment_allocations')->where('tenant_id', $tenant->id)->where('sale_id', $sale->id)->count() + 1;
            $paymentNumber = 'PAY-'.$sale->sale_number.'-R'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
            $now = now($tenant->timezone);
            $paymentId = (string) Str::uuid();
            DB::table('payments')->insert([
                'id' => $paymentId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'cash_shift_id' => $shift->id,
                'customer_id' => $sale->customer_id, 'cash_account_id' => $cashAccount->id, 'confirmed_by_user_id' => auth()->id(),
                'payment_number' => $paymentNumber, 'method' => $data['method'], 'status' => 'confirmed', 'amount' => $amount,
                'received_at' => $now, 'confirmed_at' => $now, 'idempotency_key' => 'receivable:'.$sale->id.':'.$sequence,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('payment_allocations')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'payment_id' => $paymentId,
                'sale_id' => $sale->id, 'amount' => $amount, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('sales')->where('id', $sale->id)->update([
                'paid_total' => round((float) $sale->paid_total + $amount, 2), 'updated_at' => $now,
            ]);

            $receivable = DB::table('chart_of_accounts')->where('tenant_id', $tenant->id)->where('code', '1200')->first();
            $entryId = (string) Str::uuid();
            DB::table('journal_entries')->insert([
                'id' => $entryId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id,
                'entry_number' => 'RCV-'.$paymentNumber, 'entry_date' => $now->toDateString(), 'status' => 'posted',
                'description' => 'Penerimaan kasbon '.$sale->sale_number, 'source_type' => 'payment', 'source_id' => $paymentId,
                'idempotency_key' => 'payment:'.$paymentId.':journal', 'posted_at' => $now,
                'posted_by_user_id' => auth()->id(), 'created_by_user_id' => auth()->id(), 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ([[$cashAccount->chart_account_id, $amount, 0], [$receivable->id, 0, $amount]] as [$accountId, $debit, $credit]) {
                DB::table('journal_lines')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'journal_entry_id' => $entryId,
                    'account_id' => $accountId, 'description' => 'Pelunasan '.$sale->sale_number,
                    'debit' => $debit, 'credit' => $credit, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id,
                'actor_user_id' => auth()->id(), 'action' => 'sale.payment_received', 'subject_type' => 'sale',
                'subject_id' => $sale->id, 'after' => json_encode(['amount' => $amount, 'payment_number' => $paymentNumber]),
                'occurred_at' => $now, 'created_at' => $now,
            ]);
        }, attempts: 3);

        return redirect()->route('sales.show', $id)->with('success', 'Pembayaran pelanggan berhasil dicatat.');
    }
}
