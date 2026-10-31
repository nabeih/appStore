<?php

namespace App\Http\Controllers\Web\Search;

use App\Actions\Search\ProductSearchAction;
use App\Actions\Search\SearchTerm;
use App\Actions\Search\VisualProductSearchAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Search\ProductSearchRequest;
use App\Http\Requests\Search\VisualProductSearchRequest;
use App\Support\Search\Code128;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductSearchController extends Controller
{
    public function codes(ProductSearchRequest $request, ProductSearchAction $search): JsonResponse
    {
        $data = $request->validate(['codes' => ['required', 'array', 'list', 'min:1', 'max:12'], 'codes.*' => ['required', 'string', 'max:100']]);
        $codes = array_values(array_unique(array_map(SearchTerm::normalize(...), $data['codes'])));
        $filters = $request->validated();
        unset($filters['q']);
        $records = $search->query($request->user(), $filters)
            ->whereHas('productVariant', fn ($query) => $query->whereIn('sku', $codes))->limit(120)->get();

        return response()->json(['data' => $records->map(fn ($stock): array => $search->describe($stock, $request->user()))->values()]);
    }

    public function visual(VisualProductSearchRequest $request, VisualProductSearchAction $search): JsonResponse
    {
        return response()->json(['data' => $search->execute($request->user(), $request->file('image'), (int) $request->validated('branch_id'))]);
    }

    public function index(ProductSearchRequest $request, ProductSearchAction $search): JsonResponse
    {
        $data = $request->validated();
        $records = $search->query($request->user(), $data)->orderBy('inventories.id')->paginate(20)->withQueryString()
            ->through(fn ($stock): array => $search->describe($stock, $request->user()));

        return response()->json($records);
    }

    public function labels(ProductSearchRequest $request, ProductSearchAction $search, Code128 $barcode): View
    {
        $data = $request->validate(['variants' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'variants.*' => ['required', 'integer', 'min:1', 'distinct'],
            'copies' => ['sometimes', 'integer', 'min:1', 'max:20']]);
        $records = $search->query($request->user(), $request->validated())->whereIn('inventories.product_variant_id', $data['variants'])->get()->unique('product_variant_id');
        abort_unless($records->count() === count($data['variants']), 404, 'أحد الأصناف غير موجود ضمن الفروع المسموحة.');
        $labels = $records->map(fn ($stock): array => ['item' => $search->describe($stock, $request->user()),
            'svg' => Code128::supports($stock->productVariant->sku) ? $barcode->svg($stock->productVariant->sku) : null]);
        $copies = $data['copies'] ?? 1;
        if ($records->count() * $copies > 200) {
            throw ValidationException::withMessages(['copies' => 'الحد الأقصى 200 ملصق في الطلب الواحد.']);
        }

        return view('search.labels', compact('labels', 'copies'));
    }
}
