<?php

namespace App\Actions\Inventory\Products;

use App\Actions\Catalog\VisibleProductsQuery;
use App\Models\Product;
use App\Models\User;
use App\Support\Sales\SalesReports;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;

class ShowInventoryProductAction
{
    public function __construct(private VisibleProductsQuery $visible) {}

    public function execute(User $actor, int $id): Product
    {
        Gate::forUser($actor)->authorize('inventory.view');
        $branches = SalesReports::branches($actor, false)->modelKeys();
        $cost = $actor->can('sales.view_cost');
        $product = $this->visible->execute($actor, true)->with('category:id,name')->findOrFail($id);
        $product->load(['variants' => function (HasMany $variants) use ($branches, $cost): void {
            $variants->select(['id', 'product_id', 'color_id', 'size_id', 'sku', 'selling_price', 'is_active'])
                ->when($cost, fn (Builder $query) => $query->addSelect('minimum_selling_price'))
                ->where(fn (Builder $query) => $query->whereHas('inventories', fn (Builder $stock) => $stock->whereIn('branch_id', $branches))
                    ->orWhereDoesntHave('inventories'))
                ->orderBy('id');
        }, 'variants.color:id,name,hex_code', 'variants.size:id,name',
            'variants.inventories' => fn (HasMany $query) => $query->select(['id', 'product_variant_id', 'branch_id', 'quantity'])
                ->when($cost, fn (Builder $stock) => $stock->addSelect(['average_cost', 'value_minor']))
                ->whereIn('branch_id', $branches)->orderBy('branch_id'),
            'variants.inventories.branch:id,name']);
        $colors = $product->variants->pluck('color_id')->unique()->all();
        $product->load(['images' => fn (HasMany $query) => $query->select(['id', 'product_id', 'color_id', 'image_path', 'thumbnail_path'])->whereIn('color_id', $colors),
            'swatches' => fn (HasMany $query) => $query->whereIn('color_id', $colors)]);

        return $product;
    }
}
