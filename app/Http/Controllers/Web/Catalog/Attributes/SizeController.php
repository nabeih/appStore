<?php

namespace App\Http\Controllers\Web\Catalog\Attributes;

use App\Actions\Catalog\CreateSizeAction;
use App\Actions\Catalog\DeleteSizeAction;
use App\Actions\Catalog\UpdateSizeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\Attributes\StoreSizeRequest;
use App\Http\Requests\Catalog\Attributes\UpdateSizeRequest;
use App\Models\Size;
use App\Support\Sales\OperationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SizeController extends Controller
{
    public function store(StoreSizeRequest $request, CreateSizeAction $create): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $sizes = OperationContext::run(function () use ($data, $create): array {
            return array_map(fn ($name) => $create->execute(['name' => $name, 'sort_order' => $data['sort_order'] ?? 0]
                + (empty($data['names']) ? ['code' => $data['code'] ?? null] : [])), $data['names'] ?? [$data['name']]);
        });
        $size = $sizes[0];
        $message = count($sizes) === 1 ? 'تمت إضافة المقاس بنجاح.' : 'تمت إضافة '.count($sizes).' مقاسات بنجاح دون تكرار.';

        return $request->expectsJson() ? response()->json(['message' => $message, 'id' => $size->id, 'tab' => 'sizes'], 201)
            : redirect()->route('catalog.attributes.index', ['tab' => 'sizes'])->with('success', $message);
    }

    public function update(UpdateSizeRequest $request, Size $size, UpdateSizeAction $update): JsonResponse|RedirectResponse
    {
        $update->execute($size, $request->validated());
        $message = 'تم تعديل اسم المقاس وترتيبه.';

        return $request->expectsJson() ? response()->json(['message' => $message, 'id' => $size->id, 'tab' => 'sizes'])
            : redirect()->route('catalog.attributes.index', ['tab' => 'sizes'])->with('success', $message);
    }

    public function destroy(Request $request, Size $size, DeleteSizeAction $delete): JsonResponse|RedirectResponse
    {
        $delete->execute($size);
        $message = 'تم حذف المقاس.';

        return $request->expectsJson() ? response()->json(['message' => $message, 'id' => $size->id, 'tab' => 'sizes'])
            : redirect()->route('catalog.attributes.index', ['tab' => 'sizes'])->with('success', $message);
    }
}
