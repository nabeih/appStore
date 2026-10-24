<?php

namespace App\Http\Controllers\Web\Catalog\Attributes;

use App\Actions\Catalog\CreateSizeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\Attributes\StoreSizeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class SizeController extends Controller
{
    public function store(StoreSizeRequest $request, CreateSizeAction $create): JsonResponse|RedirectResponse
    {
        $size = $create->execute($request->validated());
        $message = 'تمت إضافة المقاس بنجاح.';

        return $request->expectsJson() ? response()->json(['message' => $message, 'id' => $size->id, 'tab' => 'sizes'], 201)
            : redirect()->route('catalog.attributes.index', ['tab' => 'sizes'])->with('success', $message);
    }
}
