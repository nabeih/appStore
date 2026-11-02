<?php

namespace App\Actions\Mobile;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Color;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\FinancialAccount;
use App\Models\Inventory;
use App\Models\OpeningStockEntry;
use App\Models\Product;
use App\Models\ProductColorSwatch;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\PurchaseInvoice;
use App\Models\SaleInvoice;
use App\Models\Size;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Mobile\MobileRevision;
use App\Support\Sales\Money;
use App\Support\Search\Code128;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ReadMobileData
{
    /** @return list<string> */
    public function allowed(User $actor): array
    {
        $entities = ['branches'];
        if ($actor->can('catalog.search')) {
            array_push($entities, 'categories', 'colors', 'sizes');
        }
        if ($actor->can('catalog.search') && $actor->can('inventory.view')) {
            array_push($entities, 'products', 'variants', 'images', 'swatches');
        }
        if ($actor->can('inventory.view')) {
            $entities[] = 'stocks';
        }
        if ($actor->can('sales.customers')) {
            array_push($entities, 'customers', 'customer_balances');
        }
        if ($actor->can('sales.view')) {
            $entities[] = 'sales';
        }
        if ($actor->can('sales.collect') && $actor->can('sales.customers')) {
            $entities[] = 'receipts';
        }
        if ($actor->can('sales.sell') || $actor->can('sales.collect') || $actor->can('sales.manage_accounts')) {
            $entities[] = 'accounts';
        }
        if ($actor->can('purchases.view') && $actor->can('sales.view_cost')) {
            array_push($entities, 'suppliers', 'purchases');
        }
        if ($actor->can('inventory.correct_setup') && $actor->can('sales.view_cost')) {
            $entities[] = 'openings';
        }

        return $entities;
    }

    private function products(User $actor, int $branch): Builder
    {
        return Product::where(function (Builder $query) use ($actor, $branch): void {
            $query->whereHas('variants.inventories', fn (Builder $stock) => $stock->where('branch_id', $branch));
            if ($actor->can('inventory.manage_catalog')) {
                $query->orWhereDoesntHave('variants.inventories');
            }
        });
    }

    private function variants(User $actor, int $branch): Builder
    {
        return ProductVariant::whereIn('product_id', $this->products($actor, $branch)->select('products.id'))
            ->where(function (Builder $query) use ($actor, $branch): void {
                $query->whereHas('inventories', fn (Builder $stock) => $stock->where('branch_id', $branch));
                if ($actor->can('inventory.manage_catalog')) {
                    $query->orWhereDoesntHave('inventories');
                }
            });
    }

    public function query(User $actor, int $branch, string $entity, bool $authorizeScope = true): Builder
    {
        if ($authorizeScope) {
            app(MobileAccess::class)->scope($actor, $branch);
        }
        abort_unless(in_array($entity, $this->allowed($actor), true), 403, 'البيانات غير مسموحة.');

        return match ($entity) {
            'branches' => Branch::whereKey($branch),
            'products' => $this->products($actor, $branch)->with('variants'),
            'variants' => $this->variants($actor, $branch),
            'images' => ProductImage::whereIn('product_id', $this->products($actor, $branch)->select('products.id'))
                ->whereExists(fn ($query) => $query->selectRaw('1')->fromSub($this->variants($actor, $branch)->select('product_id', 'color_id'), 'visible_variant')
                    ->whereColumn('visible_variant.product_id', 'product_images.product_id')->whereColumn('visible_variant.color_id', 'product_images.color_id')),
            'swatches' => ProductColorSwatch::whereIn('product_id', $this->products($actor, $branch)->select('products.id'))
                ->whereExists(fn ($query) => $query->selectRaw('1')->fromSub($this->variants($actor, $branch)->select('product_id', 'color_id'), 'visible_variant')
                    ->whereColumn('visible_variant.product_id', 'product_color_swatches.product_id')->whereColumn('visible_variant.color_id', 'product_color_swatches.color_id')),
            'categories' => Category::query(), 'colors' => Color::query(), 'sizes' => Size::query(),
            'stocks' => Inventory::where('branch_id', $branch),
            'customers' => Customer::query(),
            'customer_balances' => $this->balances($branch),
            'sales' => SaleInvoice::where('branch_id', $branch)->with('items'),
            'receipts' => CustomerReceipt::where('branch_id', $branch),
            'accounts' => FinancialAccount::where('branch_id', $branch)->whereIn('type', ['cash', 'bank', 'wallet'])->where('is_active', true),
            'suppliers' => Supplier::query(),
            'purchases' => PurchaseInvoice::where('branch_id', $branch)->with('items'),
            'openings' => OpeningStockEntry::where('branch_id', $branch)->with('items'),
            default => abort(404),
        };
    }

    private function balances(int $branch): Builder
    {
        $balances = DB::table('journal_lines as lines')->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('financial_accounts as accounts', 'accounts.id', '=', 'lines.financial_account_id')->where('entries.branch_id', $branch)
            ->whereNotNull('lines.customer_id')->whereIn('accounts.code', ['receivables', 'customer_credit'])->groupBy('lines.customer_id')
            ->select('lines.customer_id')->selectRaw("SUM(CASE WHEN accounts.code = 'receivables' THEN lines.debit_minor ELSE 0 END) - SUM(CASE WHEN accounts.code = 'receivables' THEN lines.credit_minor ELSE 0 END) AS debt_minor")
            ->selectRaw("SUM(CASE WHEN accounts.code = 'customer_credit' THEN lines.credit_minor ELSE 0 END) - SUM(CASE WHEN accounts.code = 'customer_credit' THEN lines.debit_minor ELSE 0 END) AS credit_minor");

        return Customer::joinSub($balances, 'balances', 'balances.customer_id', '=', 'customers.id')
            ->select('customers.id', 'balances.debt_minor', 'balances.credit_minor');
    }

    public function describe(string $entity, Model $record, User $actor): array
    {
        $fields = match ($entity) {
            'products' => ['id', 'code', 'name', 'category_id', 'description', 'is_active'],
            'variants' => ['id', 'product_id', 'color_id', 'size_id', 'sku', 'selling_price', 'minimum_selling_price', 'is_active'],
            'stocks' => ['id', 'branch_id', 'product_variant_id', 'quantity', 'quarantined_quantity', 'damaged_quantity'],
            'categories' => ['id', 'name', 'parent_id', 'is_active'],
            'colors' => ['id', 'name', 'code', 'hex_code', 'is_active'],
            'sizes' => ['id', 'name', 'code', 'sort_order', 'is_active'],
            'branches' => ['id', 'name', 'address', 'phone', 'type', 'is_active', 'opening_stock_closed_at'],
            'customers' => ['id', 'name', 'phone'],
            'customer_balances' => ['id', 'debt_minor', 'credit_minor'],
            'accounts' => ['id', 'branch_id', 'name', 'type', 'is_active'],
            'suppliers' => ['id', 'name', 'phone1', 'phone2'],
            'images', 'swatches' => ['id', 'product_id', 'color_id'],
            'sales' => ['id', 'branch_id', 'number', 'invoice_date', 'status', 'version', 'customer_id', 'customer_name', 'cashier_name', 'total_quantity',
                'total_minor', 'paid_minor', 'released_minor', 'returned_minor', 'due_minor'],
            'receipts' => ['id', 'branch_id', 'number', 'customer_id', 'transaction_date', 'kind', 'status', 'amount_minor'],
            'purchases' => ['id', 'branch_id', 'number', 'supplier_id', 'supplier_name', 'invoice_date', 'status', 'version', 'total_minor', 'paid_minor'],
            'openings' => ['id', 'branch_id', 'number', 'opening_date', 'status', 'version', 'total_quantity', 'total_cost'],
            default => [],
        };
        if ($actor->can('sales.view_cost')) {
            $fields = [...$fields, ...match ($entity) {
                'stocks' => ['average_cost', 'value_minor', 'quarantined_value_minor'],
                'variants' => ['minimum_selling_price'], 'sales' => ['cost_minor'], default => [],
            }];
        }
        $data = $record->only($fields);
        if ($entity === 'stocks') {
            $data['reserved_quantity'] = (int) ($record->reserved_quantity ?? 0);
            $data['available_quantity'] = max(0, (int) $record->quantity - $data['reserved_quantity']);
        }
        foreach ($data as $key => $value) {
            if (str_ends_with($key, '_minor') && $value !== null) {
                $data[$key] = Money::integer((string) $value);
            }
        }
        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = (bool) $data['is_active'];
        }
        if ($entity === 'variants') {
            $data['barcode_supported'] = Code128::supports($record->sku);
        }
        if ($entity === 'colors') {
            $data['hex'] = $record->display_hex;
        }
        foreach (['invoice_date', 'opening_date', 'transaction_date'] as $dateField) {
            if (isset($data[$dateField])) {
                $data[$dateField] = CarbonImmutable::parse($data[$dateField])->toDateString();
            }
        }
        if (isset($data['opening_stock_closed_at'])) {
            $data['opening_stock_closed_at'] = CarbonImmutable::parse($data['opening_stock_closed_at'], config('app.timezone'))->utc()->toISOString();
        }
        $data['revision'] = $entity === 'products' ? MobileRevision::product($record) : MobileRevision::of($record);
        if ($entity === 'images') {
            $data += ['thumbnail_url' => $record->thumbnail_url, 'optimized_url' => $record->optimized_url];
        } elseif ($entity === 'swatches') {
            $data['url'] = $record->url;
        }
        if (in_array($entity, ['sales', 'purchases', 'openings'], true)) {
            $itemFields = $entity === 'sales' ? ['id', 'product_variant_id', 'product_name', 'product_code', 'sku', 'color_name', 'size_name', 'quantity', 'unit_price_minor', 'net_minor', 'returned_quantity']
                : ['id', 'product_variant_id', 'product_name', 'product_code', 'sku', 'color_name', 'size_name', 'quantity', 'unit_cost', 'line_total'];
            if ($entity === 'purchases') {
                array_push($itemFields, 'unit_cost_minor', 'line_total_minor');
            }
            if ($actor->can('sales.view_cost') && $entity === 'sales') {
                array_push($itemFields, 'unit_cost', 'cost_minor');
            }
            $data['items'] = $record->items->map(fn ($item): array => $item->only($itemFields))->all();
        }

        return $data;
    }
}
