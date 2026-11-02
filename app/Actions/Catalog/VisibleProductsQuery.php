<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Sales\SalesReports;
use Illuminate\Database\Eloquent\Builder;

class VisibleProductsQuery
{
    public function variants(User $actor, ?int $branchId = null): Builder
    {
        $branches = SalesReports::branches($actor, false)->modelKeys();
        if ($branchId !== null) {
            $branches = array_values(array_intersect($branches, [$branchId]));
        }

        return ProductVariant::query()->where(function (Builder $query) use ($actor, $branches): void {
            $query->whereHas('inventories', fn (Builder $stock) => $stock->whereIn('branch_id', $branches));
            if ($actor->can('inventory.manage_catalog')) {
                $query->orWhereDoesntHave('inventories');
            }
        });
    }

    public function execute(User $actor, bool $withArchived = false): Builder
    {
        $branches = SalesReports::branches($actor, false)->modelKeys();

        return Product::query()->when($withArchived, fn (Builder $query) => $query->withTrashed())
            ->where(function (Builder $query) use ($actor, $branches): void {
                $query->whereHas('variants.inventories', fn (Builder $stock) => $stock->whereIn('branch_id', $branches));
                if ($actor->can('inventory.manage_catalog')) {
                    $query->orWhereDoesntHave('variants.inventories');
                }
            });
    }
}
