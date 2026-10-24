<?php

namespace App\Http\Controllers\Web\Catalog\Attributes;

use App\Actions\Catalog\CreateColorAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\Attributes\StoreColorRequest;
use App\Http\Requests\Catalog\Attributes\UpdateColorAppearanceRequest;
use App\Models\Color;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class ColorController extends Controller
{
    public function store(StoreColorRequest $request, CreateColorAction $create): JsonResponse|RedirectResponse
    {
        $color = $create->execute($request->validated());
        $message = 'تمت إضافة اللون ودرجته بنجاح.';

        return $request->expectsJson() ? response()->json(['message' => $message, 'id' => $color->id, 'tab' => 'colors'], 201)
            : redirect()->route('catalog.attributes.index', ['tab' => 'colors'])->with('success', $message);
    }

    public function updateAppearance(UpdateColorAppearanceRequest $request, Color $color): JsonResponse|RedirectResponse
    {
        $color->update($request->validated());
        $message = 'تم حفظ درجة اللون.';

        return $request->expectsJson() ? response()->json(['message' => $message, 'id' => $color->id, 'tab' => 'colors'])
            : redirect()->route('catalog.attributes.index', ['tab' => 'colors'])->with('success', $message);
    }
}
