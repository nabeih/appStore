<?php

namespace App\Actions\Search;

use App\Models\Inventory;
use App\Models\User;
use App\Support\Search\Code128;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class ProductSearchAction
{
    public function __construct(private ModuleSearch $search) {}

    /** @param array<string, mixed> $filters */
    public function query(User $actor, array $filters): Builder
    {
        Gate::forUser($actor)->authorize('catalog.search');
        Gate::forUser($actor)->authorize('inventory.view');
        $query = $this->search->inventory(Inventory::query(), $filters, $actor)
            ->whereHas('branch', fn (Builder $branch) => $branch->where('is_active', true))
            ->whereHas('productVariant', fn (Builder $variant) => $variant->where('is_active', true)
                ->whereHas('product', fn (Builder $product) => $product->where('is_active', true)->whereHas('category', fn (Builder $category) => $category->where('is_active', true)))
                ->whereHas('color', fn (Builder $color) => $color->where('is_active', true))
                ->whereHas('size', fn (Builder $size) => $size->where('is_active', true)))
            ->with(['productVariant.product.images', 'productVariant.product.swatches', 'productVariant.color', 'productVariant.size', 'branch']);

        return $this->search->rankInventory($query, $filters['q'] ?? '');
    }

    /** @return array<string, mixed> */
    public function describe(Inventory $stock, User $actor): array
    {
        $variant = $stock->productVariant;
        $product = $variant->product;
        $image = $product->images->firstWhere('color_id', $variant->color_id);
        $canSell = $actor->can('sales.sell') && $actor->can('sales.view');
        $data = ['product_id' => $product->id, 'product_variant_id' => $variant->id, 'name' => $product->name, 'code' => $product->code,
            'sku' => $variant->sku, 'color_id' => $variant->color_id, 'color' => $variant->color->name, 'size_id' => $variant->size_id, 'size' => $variant->size->name,
            'branch_id' => $stock->branch_id, 'branch' => $stock->branch->name, 'quantity' => (int) $stock->quantity,
            'image_url' => $image?->thumbnail_url, 'swatch_url' => $product->swatches->firstWhere('color_id', $variant->color_id)?->url,
            'unit_price' => $variant->selling_price, 'setup_open' => ! $stock->branch->opening_stock_closed_at,
            'inventory_url' => route('inventory.index', ['q' => $variant->sku, 'branch_id' => $stock->branch_id, 'match' => 'exact']),
            'sale_url' => $canSell ? route('sales.cashier', ['q' => $variant->sku, 'branch_id' => $stock->branch_id]) : null,
            'label_url' => route('search.products.labels', ['variants' => [$variant->id], 'branch_id' => $stock->branch_id]),
            'barcode_supported' => Code128::supports($variant->sku)];
        if ($actor->can('sales.view_cost')) {
            $data['average_cost'] = $stock->value_minor === null ? null : (string) $stock->average_cost;
        }

        return $data;
    }
}
