<?php

namespace App\Http\Controllers\Web\Inventory\OpeningStock;

use App\Actions\Catalog\CreateCategoryAction;
use App\Actions\Catalog\CreateColorAction;
use App\Actions\Catalog\CreateSizeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\OpeningStock\StoreOpeningStockOptionRequest;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\Size;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class OpeningStockCatalogController extends Controller
{
    public function options(): JsonResponse
    {
        return response()->json([
            'limits' => [
                'max_images' => (int) ini_get('max_file_uploads'),
                'max_image_bytes' => min(4 * 1024 * 1024, UploadedFile::getMaxFilesize()),
                'max_request_bytes' => ini_parse_quantity(ini_get('post_max_size')) ?: PHP_INT_MAX,
            ],
            'categories' => Category::where('is_active', true)->orderBy('name')->get(['id', 'name', 'parent_id']),
            'colors' => Color::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'hex_code']),
            'sizes' => Size::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'code']),
        ]);
    }

    public function store(StoreOpeningStockOptionRequest $request, CreateCategoryAction $category, CreateColorAction $color, CreateSizeAction $size): JsonResponse
    {
        $data = $request->validated();
        $option = match ($data['type']) {
            'category' => $category->execute($data),
            'color' => $color->execute($data),
            'size' => $size->execute($data),
        };

        return response()->json(['message' => 'تمت الإضافة.', 'data' => $option], 201);
    }

    public function images(Request $request): JsonResponse
    {
        $data = $request->validate(['rows' => ['required', 'array', 'list', 'min:1', 'max:300'],
            'rows.*' => ['required', 'array:name,code,category_id,color_id'], 'rows.*.name' => ['required', 'string', 'max:200'],
            'rows.*.code' => ['nullable', 'string', 'max:50'], 'rows.*.category_id' => ['required', 'integer', 'min:1'], 'rows.*.color_id' => ['required', 'integer', 'min:1']]);
        $input = collect($data['rows']);
        $products = Product::where('is_active', true)->with('images')
            ->where(fn ($query) => $query->whereIn('code', $input->pluck('code')->filter()->map(fn ($code) => strtoupper($code)))
                ->orWhere(fn ($query) => $query->whereIn(DB::raw('LOWER(name)'), $input->pluck('name')->map(fn ($name) => mb_strtolower($name)))
                    ->whereIn('category_id', $input->pluck('category_id'))))->get();
        $byCode = $products->keyBy(fn (Product $product): string => strtoupper($product->code));
        $byName = $products->groupBy(fn (Product $product): string => $product->category_id.':'.mb_strtolower($product->name));

        return response()->json(['data' => $input->map(function (array $row) use ($byCode, $byName): array {
            $matches = $byName->get($row['category_id'].':'.mb_strtolower($row['name']), collect());
            $product = filled($row['code'] ?? null) ? $byCode->get(strtoupper($row['code'])) : ($matches->count() === 1 ? $matches->first() : null);

            return ['product_id' => $product?->id, 'image_url' => $product?->images->firstWhere('color_id', (int) $row['color_id'])?->url];
        })]);
    }

    public function products(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $products = Product::where('is_active', true)->with(['variants', 'images'])
            ->where(fn ($query) => $query->where('code', 'like', '%'.$data['q'].'%')->orWhere('name', 'like', '%'.$data['q'].'%'))
            ->orderBy('name')->limit(20)->get();

        return response()->json(['data' => $products->map(fn (Product $product): array => [
            'id' => $product->id, 'code' => $product->code, 'name' => $product->name, 'category_id' => $product->category_id,
            'image_colors' => $product->images->pluck('color_id'),
            'images' => $product->images->map(fn ($image): array => ['color_id' => $image->color_id, 'url' => $image->url]),
            'variants' => $product->variants->where('is_active', true)->values()->map(fn ($variant): array => [
                'color_id' => $variant->color_id, 'size_id' => $variant->size_id, 'selling_price' => $variant->selling_price,
            ]),
        ])]);
    }
}
