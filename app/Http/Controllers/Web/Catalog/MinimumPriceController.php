<?php

namespace App\Http\Controllers\Web\Catalog;

use App\Actions\Catalog\SetMinimumPrices;
use App\Actions\Catalog\VisibleProductsQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\MinimumPriceRequest;
use App\Support\Access\AccessPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MinimumPriceController extends Controller
{
    public function edit(Request $request, int $product): View
    {
        abort_unless(AccessPermissions::isOwner($request->user()), 403);

        return view('store.admin.minimum-prices', ['entry' => app(VisibleProductsQuery::class)->execute($request->user())
            ->with(['variants.color', 'variants.size'])->findOrFail($product)]);
    }

    public function update(MinimumPriceRequest $request, int $product, SetMinimumPrices $set): RedirectResponse
    {
        $entry = $set->execute($product, $request->validated(), $request->user());

        return redirect()->route('inventory.products.show', $entry)->with('success', 'تم تحديث الحدود الدنيا؛ الفواتير السابقة احتفظت بلقطتها الأصلية.');
    }
}
