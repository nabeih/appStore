<?php

namespace App\Http\Controllers\Web\Catalog\Attributes;

use App\Actions\Catalog\CreateColorAction;
use App\Actions\Catalog\DeleteColorAction;
use App\Actions\Catalog\UpdateColorAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\Attributes\StoreColorRequest;
use App\Http\Requests\Catalog\Attributes\UpdateColorAppearanceRequest;
use App\Http\Requests\Catalog\Attributes\UpdateColorRequest;
use App\Models\Color;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ColorController extends Controller
{
    public function store(StoreColorRequest $request, CreateColorAction $create): JsonResponse|RedirectResponse
    {
        $color = $create->execute($request->validated());
        $message = 'تمت إضافة اللون ودرجته بنجاح.';

        return $request->expectsJson() ? response()->json(['message' => $message, 'id' => $color->id, 'tab' => 'colors'], 201)
            : redirect()->route('catalog.attributes.index', ['tab' => 'colors'])->with('success', $message);
    }

    public function update(UpdateColorRequest $request, Color $color, UpdateColorAction $update): JsonResponse|RedirectResponse
    {
        $update->execute($color, $request->validated());
        $message = 'تم تعديل اسم اللون ودرجته.';

        return $request->expectsJson() ? response()->json(['message' => $message, 'id' => $color->id, 'tab' => 'colors'])
            : redirect()->route('catalog.attributes.index', ['tab' => 'colors'])->with('success', $message);
    }

    public function destroy(Request $request, Color $color, DeleteColorAction $delete): JsonResponse|RedirectResponse
    {
        $delete->execute($color);
        $message = 'تم حذف اللون.';

        return $request->expectsJson() ? response()->json(['message' => $message, 'id' => $color->id, 'tab' => 'colors'])
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
