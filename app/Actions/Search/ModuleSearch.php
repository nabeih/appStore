<?php

namespace App\Actions\Search;

use App\Models\User;
use App\Support\Sales\Money;
use App\Support\Sales\SalesReports;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class ModuleSearch
{
    /** @var array<string,list<int>> */
    private array $branchIds = [];

    public function branches(Builder|Relation $query, User $actor, string $column = 'branch_id'): Builder
    {
        $query = $query instanceof Relation ? $query->getQuery() : $query;

        $key = $actor->id.':'.$actor->branch_id.':'.(int) $actor->can('sales.all_branches');
        $ids = $this->branchIds[$key] ??= SalesReports::branches($actor, false)->modelKeys();

        return $query->whereIn($column, $ids);
    }

    public function variants(Builder|Relation $query, array $filters, bool $withArchivedProducts = false): Builder
    {
        $query = $query instanceof Relation ? $query->getQuery() : $query;
        $term = SearchTerm::normalize($filters['q'] ?? '');
        $mode = $filters['match'] ?? 'contains';
        $product = function (Builder $product) use ($withArchivedProducts): void {
            if ($withArchivedProducts) {
                $product->withTrashed();
            }
        };
        if ($term !== '') {
            $query->where(function (Builder $variant) use ($term, $mode, $product): void {
                $variant->where(fn (Builder $code) => SearchTerm::apply($code, $term, [], ['sku'], $mode))
                    ->orWhereHas('product', function (Builder $item) use ($term, $mode, $product): void {
                        $product($item);
                        $item->where(fn (Builder $name) => SearchTerm::apply($name, $term, ['name'], ['code'], $mode))
                            ->orWhereHas('category', fn (Builder $category) => SearchTerm::apply($category, $term, ['name'], [], $mode));
                    })
                    ->orWhereHas('color', fn (Builder $color) => SearchTerm::apply($color, $term, ['name'], ['code'], $mode))
                    ->orWhereHas('size', fn (Builder $size) => SearchTerm::apply($size, $term, ['name'], ['code'], $mode));
            });
        }
        foreach (['color_id', 'size_id'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }
        if (! empty($filters['category_id'])) {
            $query->whereHas('product', function (Builder $item) use ($filters, $product): void {
                $product($item);
                $item->where('category_id', $filters['category_id']);
            });
        }
        if (($filters['active'] ?? '') === 'active') {
            $query->where('is_active', true)->whereHas('product', fn (Builder $item) => $item->where('is_active', true));
        } elseif (($filters['active'] ?? '') === 'inactive') {
            $query->where(fn (Builder $variant) => $variant->where('is_active', false)->orWhereHas('product', function (Builder $item) use ($product): void {
                $product($item);
                $item->where('is_active', false);
            }));
        }

        return $query;
    }

    public function products(Builder $query, array $filters): Builder
    {
        $term = SearchTerm::normalize($filters['q'] ?? '');
        if ($term !== '') {
            $query->where(function (Builder $product) use ($term, $filters): void {
                SearchTerm::apply($product, $term, ['name'], ['code'], $filters['match'] ?? 'contains');
                $product->orWhereHas('variants', fn (Builder $variant) => $this->variants($variant, $filters));
            });
        }
        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }
        if (! empty($filters['color_id']) || ! empty($filters['size_id']) || in_array($filters['active'] ?? '', ['active', 'inactive'], true)) {
            $query->whereHas('variants', fn (Builder $variant) => $this->variants($variant, $filters));
        }

        return $query;
    }

    public function inventory(Builder|Relation $query, array $filters, User $actor): Builder
    {
        $query = $query instanceof Relation ? $query->getQuery() : $query;
        $this->branches($query, $actor, 'inventories.branch_id');
        if (! empty($filters['branch_id'])) {
            $query->where('inventories.branch_id', $filters['branch_id']);
        }
        if (($filters['quantity'] ?? '') === 'available') {
            $query->where('inventories.quantity', '>', 0);
        }
        if (($filters['quantity'] ?? '') === 'empty') {
            $query->where('inventories.quantity', 0);
        }
        $query->whereHas('productVariant', fn (Builder $variant) => $this->variants($variant, $filters, true));
        if (($filters['valuation'] ?? '') === 'missing') {
            $query->whereNull('inventories.value_minor');
        }
        if (($filters['valuation'] ?? '') === 'reviewed') {
            $query->whereNotNull('inventories.value_minor');
        }
        if (($filters['valuation'] ?? '') === 'mismatch') {
            $query->whereRaw('inventories.quantity <> COALESCE(history.history_quantity,0)');
        }

        return $query;
    }

    public function rankProducts(Builder $query, string $term): Builder
    {
        $term = SearchTerm::normalize($term);
        if ($term === '') {
            return $query;
        }
        $prefix = SearchTerm::pattern($term, 'prefix');

        return $query->orderByRaw("CASE
            WHEN EXISTS (SELECT 1 FROM product_variants ranked WHERE ranked.product_id = products.id AND ranked.is_active = 1 AND ranked.sku = ?) THEN 0
            WHEN products.code = ? THEN 1
            WHEN products.code LIKE ? ESCAPE '!' OR EXISTS (SELECT 1 FROM product_variants ranked WHERE ranked.product_id = products.id AND ranked.is_active = 1 AND ranked.sku LIKE ? ESCAPE '!') THEN 2
            WHEN products.name = ? THEN 3 ELSE 4 END", [$term, $term, $prefix, $prefix, $term]);
    }

    public function rankInventory(Builder $query, string $term): Builder
    {
        $term = SearchTerm::normalize($term);
        if ($term === '') {
            return $query;
        }
        $prefix = SearchTerm::pattern($term, 'prefix');

        return $query->orderByRaw("(SELECT CASE WHEN ranked.sku = ? THEN 0 WHEN ranked_product.code = ? THEN 1
            WHEN ranked.sku LIKE ? ESCAPE '!' OR ranked_product.code LIKE ? ESCAPE '!' THEN 2
            WHEN ranked_product.name = ? THEN 3 ELSE 4 END
            FROM product_variants ranked JOIN products ranked_product ON ranked_product.id = ranked.product_id
            WHERE ranked.id = inventories.product_variant_id)", [$term, $term, $prefix, $prefix, $term]);
    }

    public function documents(Builder|Relation $query, array $filters, string $prefix, array $columns): Builder
    {
        $query = $query instanceof Relation ? $query->getQuery() : $query;
        $term = SearchTerm::normalize($filters['q'] ?? '');
        if ($term !== '' && str_starts_with(strtoupper($term), $prefix.'-') && ($id = SearchTerm::documentId($term, $prefix))) {
            return $this->filters($query->whereKey($id), $filters, $prefix === 'OS' ? 'opening_date' : 'invoice_date');
        }
        if ($term !== '') {
            $query->where(function (Builder $document) use ($term, $prefix, $columns, $filters): void {
                $document->where(fn (Builder $text) => SearchTerm::apply($text, $term, $columns, [], $filters['match'] ?? 'contains'));
                if ($id = SearchTerm::documentId($term, $prefix)) {
                    $document->orWhereKey($id);
                }
                $document->orWhereHas('items', fn (Builder $item) => SearchTerm::apply($item, $term, ['product_name', 'color_name', 'size_name'], ['product_code', 'sku'], $filters['match'] ?? 'contains'));
            });
        }

        return $this->filters($query, $filters, match ($prefix) {
            'OS' => 'opening_date', default => 'invoice_date'
        });
    }

    public function filters(Builder|Relation $query, array $filters, ?string $dateColumn = null, ?string $amountColumn = null): Builder
    {
        $query = $query instanceof Relation ? $query->getQuery() : $query;
        foreach (['branch_id', 'status'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }
        if ($dateColumn) {
            if (! empty($filters['from'])) {
                $query->where($dateColumn, '>=', $filters['from']);
            }
            if (! empty($filters['to'])) {
                $query->where($dateColumn, '<', CarbonImmutable::parse($filters['to'])->addDay()->toDateString());
            }
        }
        if ($amountColumn) {
            if (isset($filters['min_amount'])) {
                $query->where($amountColumn, '>=', Money::minor($filters['min_amount']));
            }
            if (isset($filters['max_amount'])) {
                $query->where($amountColumn, '<=', Money::minor($filters['max_amount']));
            }
        }

        return $query;
    }

    public function contacts(Builder|Relation $query, string $term, bool $supplier = false): Builder
    {
        $query = $query instanceof Relation ? $query->getQuery() : $query;

        return SearchTerm::apply($query, $term, $supplier ? ['name', 'phone1', 'phone2'] : ['name', 'phone']);
    }

    public function accounts(Builder|Relation $query, array $filters, User $actor): Builder
    {
        $query = $query instanceof Relation ? $query->getQuery() : $query;
        $this->branches($query, $actor);
        SearchTerm::apply($query, $filters['q'] ?? '', ['name', 'reference'], ['code'], $filters['match'] ?? 'contains');
        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }
        if (! empty($filters['account_type'])) {
            $query->where('type', $filters['account_type']);
        }
        if (in_array($filters['active'] ?? '', ['active', 'inactive'], true)) {
            $query->where('is_active', $filters['active'] === 'active');
        }

        return $query;
    }

    public function receipts(Builder|Relation $query, array $filters, User $actor, bool $supplier = false): Builder
    {
        $query = $query instanceof Relation ? $query->getQuery() : $query;
        $this->branches($query, $actor);
        $term = SearchTerm::normalize($filters['q'] ?? '');
        if ($term !== '') {
            $query->where(function (Builder $receipt) use ($term, $supplier): void {
                SearchTerm::apply($receipt, $term, $supplier ? ['reference', 'notes'] : ['notes']);
                if ($id = SearchTerm::documentId($term, $supplier ? 'SP' : 'RC')) {
                    $receipt->orWhereKey($id);
                }
                if ($supplier) {
                    $receipt->orWhereHas('invoice', fn (Builder $invoice) => $this->documents($invoice, ['q' => $term], 'PI', ['supplier_name', 'supplier_invoice_number']));
                } else {
                    $receipt->orWhereHas('customer', fn (Builder $customer) => $this->contacts($customer->withTrashed(), $term))
                        ->orWhereHas('parts', fn (Builder $part) => SearchTerm::apply($part, $term, ['reference']))
                        ->orWhereHas('parts.account', fn (Builder $account) => SearchTerm::apply($account, $term, ['name', 'reference'], ['code']))
                        ->orWhereHas('allocations.invoice', fn (Builder $invoice) => $this->documents($invoice, ['q' => $term], 'SL', ['customer_name', 'cashier_name']));
                }
            });
        }
        if (! $supplier && ! empty($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }
        if ($supplier) {
            unset($filters['status']);
        }

        return $this->filters($query, $filters, $supplier ? 'payment_date' : 'transaction_date', 'amount_minor');
    }

    public function sort(Builder|Relation $query, array $filters, ?string $nameColumn = null, string $idColumn = 'id'): Builder
    {
        $query = $query instanceof Relation ? $query->getQuery() : $query;

        return match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->orderBy($idColumn),
            'name' => $nameColumn ? $query->orderBy($nameColumn)->orderBy($idColumn) : $query->orderByDesc($idColumn),
            default => $query->orderByDesc($idColumn),
        };
    }
}
