<?php

namespace App\Http\Controllers\Web\Inventory;

use App\Actions\Inventory\Products\ShowInventoryProductAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Search\SearchRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class ProductDetailsController extends Controller
{
    public function show(Request $request, int $product, ShowInventoryProductAction $details): View
    {
        $model = $details->execute($request->user(), $product);
        $keys = ['q', 'page', 'branch_id', 'category_id', 'color_id', 'size_id', 'active', 'quantity', 'match', 'sort'];
        $rules = array_intersect_key(SearchRequest::filterRules(), array_flip($keys));
        $raw = $request->query('list', []);
        $filters = Validator::make(is_array($raw) ? $raw : [], $rules)->validated();
        $backUrl = route('inventory.index', $filters);
        $canManage = ! $model->trashed() && $request->user()->can('inventory.manage_catalog');

        return view('inventory.products.show', ['product' => $model, 'backUrl' => $backUrl, 'canManage' => $canManage]);
    }
}
