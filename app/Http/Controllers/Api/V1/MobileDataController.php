<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Mobile\ReadMobileData;
use App\Actions\Search\ModuleSearch;
use App\Actions\Search\SearchTerm;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MobileDataController extends Controller
{
    public function index(Request $request, string $entity, ReadMobileData $data): JsonResponse
    {
        $input = $request->validate(['branch_id' => ['required', 'integer', 'min:1'], 'after_id' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'q' => ['nullable', 'string', 'max:100'], 'match' => ['nullable', 'in:contains,exact,prefix']]);
        $query = $data->query($request->user(), $input['branch_id'], $entity);
        $key = $query->qualifyColumn('id');
        if (filled($input['q'] ?? null)) {
            abort_unless(in_array($entity, ['products', 'variants', 'customers', 'suppliers', 'sales'], true), 422, 'البحث غير مدعوم لهذا النوع.');
            $term = SearchTerm::normalize($input['q']);
            $search = app(ModuleSearch::class);
            if ($entity === 'sales') {
                $search->documents($query, ['q' => $term, 'match' => $input['match'] ?? 'contains'], 'SL', ['customer_name', 'cashier_name', 'notes']);
            } elseif (in_array($entity, ['customers', 'suppliers'], true)) {
                $search->contacts($query, $term, $entity === 'suppliers');
            } elseif ($entity === 'variants') {
                $search->variants($query, ['q' => $term, 'match' => $input['match'] ?? 'contains']);
            } else {
                $query->where(function ($product) use ($term, $input, $data, $request): void {
                    SearchTerm::apply($product, $term, ['name'], ['code'], $input['match'] ?? 'contains');
                    $visible = $data->query($request->user(), $input['branch_id'], 'variants', false)->select('product_variants.id');
                    $product->orWhereHas('variants', fn ($variant) => SearchTerm::apply($variant->whereIn('id', $visible), $term, [], ['sku'], $input['match'] ?? 'contains'));
                });
            }
        }
        $limit = $input['limit'] ?? 50;
        $rows = $query->where($key, '>', $input['after_id'] ?? 0)->orderBy($key)->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $page = $rows->take($limit);

        return response()->json(['schema_version' => 1, 'data' => $page->map(fn ($row): array => $data->describe($entity, $row, $request->user()))->values(),
            'next_after_id' => $more ? $page->last()->id : null, 'server_timestamp' => now()->utc()->toISOString()]);
    }

    public function show(Request $request, string $entity, string $id, ReadMobileData $data): JsonResponse
    {
        $input = $request->validate(['branch_id' => ['required', 'integer', 'min:1']]);
        Validator::make(['id' => $id], ['id' => ['required', 'integer', 'min:1']])->validate();
        $record = $data->query($request->user(), $input['branch_id'], $entity)->findOrFail((int) $id);

        return response()->json(['data' => $data->describe($entity, $record, $request->user())]);
    }
}
