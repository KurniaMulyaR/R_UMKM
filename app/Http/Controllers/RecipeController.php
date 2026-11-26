<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RecipeController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $menus = DB::table('product_variants')->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('recipes', 'recipes.product_variant_id', '=', 'product_variants.id')
            ->where('products.tenant_id', $tenant->id)->where('products.is_active', true)->where('products.track_stock', false)->where('product_variants.is_active', true)->whereNull('products.deleted_at')
            ->orderBy('products.name')->orderBy('product_variants.created_at')
            ->select('product_variants.id', 'products.name as product_name', 'product_variants.name as variant_name', 'recipes.id as recipe_id', 'recipes.yield_quantity')->get()
            ->map(function ($menu) use ($tenant, $outlet) {
                $menu->price = $this->basePrice($tenant->id, $outlet->id, $menu->id);
                $menu->cost = $menu->recipe_id ? $this->portionCost($tenant->id, $outlet->id, $menu->recipe_id) : null;
                $menu->margin = $menu->cost !== null && $menu->price > 0 ? round(($menu->price - $menu->cost) / $menu->price * 100, 1) : null;

                return $menu;
            });

        return view('recipes.index', compact('menus'));
    }

    public function edit(Request $request, string $variantId): View
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $menu = $this->menuVariant($tenant->id, $variantId);
        $recipe = DB::table('recipes')->where('tenant_id', $tenant->id)->where('product_variant_id', $variantId)->first();
        $lines = $recipe ? $this->ingredientRows($tenant->id, $outlet->id, $recipe->id) : collect();
        $ingredients = DB::table('product_variants')->join('products', 'products.id', '=', 'product_variants.product_id')->join('units', 'units.id', '=', 'products.base_unit_id')
            ->where('products.tenant_id', $tenant->id)->where('products.is_active', true)->where('products.track_stock', true)->where('product_variants.is_active', true)->whereNull('products.deleted_at')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('recipes')->whereColumn('recipes.product_variant_id', 'product_variants.id'))
            ->orderBy('products.name')->select('product_variants.id', 'products.name as product_name', 'product_variants.name as variant_name', 'units.symbol')->get();
        $cost = $recipe ? $this->portionCost($tenant->id, $outlet->id, $recipe->id) : null;
        $price = $this->basePrice($tenant->id, $outlet->id, $variantId);

        return view('recipes.edit', compact('menu', 'recipe', 'lines', 'ingredients', 'cost', 'price'));
    }

    public function update(Request $request, string $variantId): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $this->menuVariant($tenant->id, $variantId);
        $data = $request->validate([
            'yield_quantity' => ['required', 'numeric', 'gt:0', 'max:999999'], 'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:60'], 'lines.*.ingredient_variant_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999'], 'lines.*.waste_percent' => ['nullable', 'numeric', 'min:0', 'max:90'],
        ]);
        foreach ($data['lines'] as $line) {
            $ok = DB::table('product_variants')->join('products', 'products.id', '=', 'product_variants.product_id')->where('product_variants.id', $line['ingredient_variant_id'])
                ->where('products.tenant_id', $tenant->id)->where('products.track_stock', true)->whereNull('products.deleted_at')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('recipes')->whereColumn('recipes.product_variant_id', 'product_variants.id'))->exists();
            if (! $ok) {
                throw ValidationException::withMessages(['lines' => 'Bahan harus produk berstok dan bukan menu beresep.']);
            }
        }
        DB::transaction(function () use ($tenant, $variantId, $data) {
            $recipe = DB::table('recipes')->where('tenant_id', $tenant->id)->where('product_variant_id', $variantId)->lockForUpdate()->first();
            if ($recipe) {
                DB::table('recipes')->where('id', $recipe->id)->update(['yield_quantity' => $data['yield_quantity'], 'notes' => $data['notes'] ?? null, 'updated_at' => now()]);
                DB::table('recipe_ingredients')->where('recipe_id', $recipe->id)->delete();
                $recipeId = $recipe->id;
            } else {
                $recipeId = (string) Str::uuid();
                DB::table('recipes')->insert(['id' => $recipeId, 'tenant_id' => $tenant->id, 'product_variant_id' => $variantId, 'yield_quantity' => $data['yield_quantity'], 'notes' => $data['notes'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ($data['lines'] as $line) {
                DB::table('recipe_ingredients')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'recipe_id' => $recipeId, 'ingredient_variant_id' => $line['ingredient_variant_id'],
                    'quantity' => $line['quantity'], 'waste_percent' => $line['waste_percent'] ?? 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return redirect()->route('recipes.edit', $variantId)->with('success', 'Resep disimpan.');
    }

    public function destroy(Request $request, string $variantId): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $this->menuVariant($tenant->id, $variantId);
        DB::table('recipes')->where('tenant_id', $tenant->id)->where('product_variant_id', $variantId)->delete();

        return redirect()->route('recipes.index')->with('success', 'Resep dihapus. Menu ini tidak lagi memotong bahan.');
    }

    /**
     * Kebutuhan bahan untuk menjual $soldQuantity porsi, dengan saldo bahan dikunci (panggil di dalam transaksi).
     * Mengembalikan null jika varian tidak punya resep.
     *
     * @return array{cost: float, lines: array<int, array{variant_id: string, name: string, quantity: float, average_cost: float, allow_negative: bool}>}|null
     */
    public static function consumption(string $tenantId, string $outletId, string $variantId, float $soldQuantity): ?array
    {
        $recipe = DB::table('recipes')->where('tenant_id', $tenantId)->where('product_variant_id', $variantId)->first();
        if (! $recipe) {
            return null;
        }
        $rows = DB::table('recipe_ingredients')->join('product_variants', 'product_variants.id', '=', 'recipe_ingredients.ingredient_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')->where('recipe_ingredients.recipe_id', $recipe->id)
            ->select('recipe_ingredients.*', 'products.name as product_name', 'products.allow_negative_stock', 'product_variants.cost_price')->get();
        $lines = [];
        $cost = 0.0;
        foreach ($rows as $row) {
            $quantity = round($soldQuantity * (float) $row->quantity * (1 + (float) $row->waste_percent / 100) / (float) $recipe->yield_quantity, 4);
            $balance = DB::table('stock_balances')->where('tenant_id', $tenantId)->where('outlet_id', $outletId)->where('product_variant_id', $row->ingredient_variant_id)->lockForUpdate()->first();
            $average = $balance && (float) $balance->average_cost > 0 ? (float) $balance->average_cost : (float) ($row->cost_price ?? 0);
            $lines[] = ['variant_id' => $row->ingredient_variant_id, 'name' => $row->product_name, 'quantity' => $quantity, 'average_cost' => $average, 'allow_negative' => (bool) $row->allow_negative_stock];
            $cost += $quantity * $average;
        }

        return ['cost' => round($cost, 2), 'lines' => $lines];
    }

    private function portionCost(string $tenantId, string $outletId, string $recipeId): float
    {
        return round($this->ingredientRows($tenantId, $outletId, $recipeId)->sum('line_cost'), 2);
    }

    private function ingredientRows(string $tenantId, string $outletId, string $recipeId)
    {
        $yield = (float) DB::table('recipes')->where('id', $recipeId)->value('yield_quantity') ?: 1.0;

        return DB::table('recipe_ingredients')->join('product_variants', 'product_variants.id', '=', 'recipe_ingredients.ingredient_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')->join('units', 'units.id', '=', 'products.base_unit_id')
            ->leftJoin('stock_balances', fn ($join) => $join->on('stock_balances.product_variant_id', '=', 'product_variants.id')->where('stock_balances.outlet_id', $outletId))
            ->where('recipe_ingredients.recipe_id', $recipeId)->orderBy('products.name')
            ->select('recipe_ingredients.*', 'products.name as product_name', 'product_variants.name as variant_name', 'units.symbol', 'product_variants.cost_price', 'stock_balances.average_cost', 'stock_balances.quantity_on_hand')
            ->get()->map(function ($row) use ($yield) {
                $average = (float) ($row->average_cost ?? 0) > 0 ? (float) $row->average_cost : (float) ($row->cost_price ?? 0);
                $row->unit_cost = $average;
                $row->line_cost = round((float) $row->quantity * (1 + (float) $row->waste_percent / 100) * $average / $yield, 2);

                return $row;
            });
    }

    private function basePrice(string $tenantId, string $outletId, string $variantId): float
    {
        $price = DB::table('product_prices')->join('product_variants', 'product_variants.id', '=', 'product_prices.product_variant_id')->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('product_prices.tenant_id', $tenantId)->where('product_prices.product_variant_id', $variantId)->whereColumn('product_prices.unit_id', 'products.base_unit_id')
            ->where('product_prices.price_type', 'retail')->where('product_prices.is_active', true)->where(fn ($q) => $q->whereNull('product_prices.outlet_id')->orWhere('product_prices.outlet_id', $outletId))
            ->orderByRaw('product_prices.outlet_id = ? desc', [$outletId])->value('product_prices.price');

        return (float) ($price ?? 0);
    }

    private function menuVariant(string $tenantId, string $variantId): object
    {
        $menu = DB::table('product_variants')->join('products', 'products.id', '=', 'product_variants.product_id')->join('units', 'units.id', '=', 'products.base_unit_id')
            ->where('products.tenant_id', $tenantId)->where('product_variants.id', $variantId)->whereNull('products.deleted_at')
            ->select('product_variants.id', 'products.name as product_name', 'product_variants.name as variant_name', 'products.track_stock', 'units.symbol')->first();
        abort_unless($menu, 404);
        if ($menu->track_stock) {
            throw ValidationException::withMessages(['recipe' => 'Resep hanya untuk produk tanpa stok sendiri (menu buat-sendiri). Matikan "lacak stok" pada produk ini dulu.']);
        }

        return $menu;
    }
}
