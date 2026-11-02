<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use App\Models\User;
use App\Support\Access\AccessPermissions;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;

class SetMinimumPrices
{
    public function execute(int $id, array $data, User $actor): Product
    {
        abort_unless(AccessPermissions::isOwner($actor) && $actor->can('inventory.manage_catalog'), 403);

        return OperationContext::run(function () use ($id, $data, $actor): Product {
            $product = app(VisibleProductsQuery::class)->execute($actor)->whereKey($id)->lockForUpdate()->firstOrFail();
            $variants = $product->variants()->lockForUpdate()->get()->keyBy('id');
            $before = $variants->map->only(['id', 'minimum_selling_price'])->all();
            foreach ($data['variants'] as $row) {
                abort_unless($variants->has($row['id']), 422, 'المتغير لا يتبع المنتج.');
            }
            $targets = ($data['apply_all'] ?? false) ? $variants : $variants->only(array_column($data['variants'], 'id'));
            foreach ($targets as $variant) {
                $value = ($data['apply_all'] ?? false) ? ($data['minimum'] ?? null) : collect($data['variants'])->firstWhere('id', $variant->id)['minimum'] ?? null;
                $variant->update(['minimum_selling_price' => $value === null || $value === '' ? null : Money::format(Money::minor($value))]);
            }
            OperationContext::audit($product, $actor, 'product.minimum_prices_updated', null, ['before' => $before,
                'after' => $product->variants()->get(['id', 'minimum_selling_price'])->toArray()]);

            return $product;
        });
    }
}
