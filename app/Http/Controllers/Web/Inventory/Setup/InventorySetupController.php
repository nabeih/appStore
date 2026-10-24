<?php

namespace App\Http\Controllers\Web\Inventory\Setup;

use App\Actions\Inventory\Setup\CorrectInventoryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\Setup\DeleteInventoryRequest;
use App\Http\Requests\Inventory\Setup\UpdateInventoryRequest;
use App\Models\Inventory;
use Illuminate\Http\JsonResponse;

class InventorySetupController extends Controller
{
    public function update(UpdateInventoryRequest $request, Inventory $inventory, CorrectInventoryAction $correct): JsonResponse
    {
        $correct->update($inventory, $request->validated(), $request->user()?->getAuthIdentifier());

        return response()->json(['message' => 'تم تعديل بيانات المخزون وتحديث الرصيد الافتتاحي المرتبط.']);
    }

    public function destroy(DeleteInventoryRequest $request, Inventory $inventory, CorrectInventoryAction $correct): JsonResponse
    {
        $correct->delete($inventory, $request->validated(), $request->user()?->getAuthIdentifier());

        return response()->json(['message' => 'تم حذف سطر المخزون من الفرع وتحديث الرصيد الافتتاحي المرتبط.']);
    }
}
