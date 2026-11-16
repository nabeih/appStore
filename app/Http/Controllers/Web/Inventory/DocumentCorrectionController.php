<?php

namespace App\Http\Controllers\Web\Inventory;

use App\Actions\Inventory\CorrectDocumentItemAction;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DocumentCorrectionController extends Controller
{
    public function show(Request $request, string $kind, int $document, int $item, CorrectDocumentItemAction $correct): View
    {
        $source = $correct->document($kind, $document);
        $correct->authorize($source, $request->user());
        $line = $source->items()->findOrFail($item);
        $previous = AuditEvent::where('subject_type', $source->getMorphClass())->where('subject_id', $source->id)->where('action', 'inventory.document_corrected')->where('data->item_id', $item)->latest('id')->first();
        $defaults = ['quantity' => $previous?->data['quantity'] ?? $line->quantity, 'unit_cost' => $previous?->data['unit_cost'] ?? $line->unit_cost];
        $plan = null;
        $problem = null;
        if ($request->has('quantity')) {
            $data = $request->validate($this->rules());
            try {
                $plan = $correct->preview($source, $item, $data, $request->user());
            } catch (ValidationException $exception) {
                $problem = implode(' ', $exception->errors()['correction'] ?? $exception->validator->errors()->all());
            }
        }

        return view('inventory.setup.document-correction', compact('source', 'line', 'kind', 'plan', 'problem', 'defaults'));
    }

    public function store(Request $request, string $kind, int $document, int $item, CorrectDocumentItemAction $correct): JsonResponse
    {
        $data = $request->validate($this->rules() + ['revision' => ['required', 'string', 'size:64'], 'operation_uuid' => ['required', 'uuid'], 'confirmed' => ['required', 'accepted']]);
        $source = $correct->document($kind, $document);
        $event = $correct->execute($source, $item, $data, $request->user());

        return response()->json(['id' => $event->id, 'message' => 'اعتُمد التصحيح كسجل مستقل؛ المستند والقيود الأصلية محفوظة.',
            'url' => route($kind === 'opening' ? 'inventory.opening-stock.show' : 'purchases.invoices.show', $source)]);
    }

    private function rules(): array
    {
        return ['quantity' => ['required', 'integer', 'min:0', 'max:1000000'], 'unit_cost' => ['required', 'regex:/\A\d{1,7}(?:\.\d{1,2})?\z/'], 'reason' => ['required', 'string', 'min:5', 'max:1000']];
    }
}
