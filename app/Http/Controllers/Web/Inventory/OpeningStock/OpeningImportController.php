<?php

namespace App\Http\Controllers\Web\Inventory\OpeningStock;

use App\Actions\Inventory\OpeningStock\ImportOpeningWorkbook;
use App\Http\Controllers\Controller;
use App\Support\ExcelDownload;
use App\Support\OpeningWorkbook;
use App\Support\Sales\SalesReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OpeningImportController extends Controller
{
    public function index(Request $request): View
    {
        $branches = SalesReports::branches($request->user())->whereNull('opening_stock_closed_at');

        return view('inventory.opening-stock.import', compact('branches'));
    }

    public function template(ExcelDownload $excel): BinaryFileResponse
    {
        return $excel->download('opening-stock-template.xlsx', ['Opening Stock' => [OpeningWorkbook::HEADERS]]);
    }

    public function preview(Request $request, ImportOpeningWorkbook $import): JsonResponse
    {
        $data = $request->validate($this->rules());

        return response()->json($import->preview($request->file('file'), $data, $request->user()))->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, ImportOpeningWorkbook $import): JsonResponse
    {
        $data = $request->validate($this->rules() + ['token' => 'required|string|max:4096', 'confirmed' => 'required|accepted']);
        $entries = $import->save($request->file('file'), $data, $data['token'], $request->user());

        $message = collect($entries)->every(fn ($entry) => $entry->status === 'draft')
            ? 'المستندات محفوظة كمسودات؛ راجع كل مستند واعتمده من شاشته. لم تُضف كميات أو قيود مالية بعد.'
            : 'هذا الاستيراد محفوظ سابقًا؛ تظهر الحالة الفعلية لكل مستند أدناه. لم تُكرر كميات أو قيود، ولم يُعد اعتماد أي مستند.';

        return response()->json(['message' => $message,
            'documents' => array_map(fn ($entry) => ['number' => $entry->number, 'status' => $entry->status, 'quantity' => $entry->total_quantity,
                'cost' => $entry->total_cost, 'url' => route('inventory.opening-stock.show', $entry)], $entries)], 201)->header('Cache-Control', 'no-store, private');
    }

    /** @return array<string, string> */
    private function rules(): array
    {
        return ['file' => 'required|file|max:4096', 'branch_id' => 'required|integer|min:1', 'opening_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'currency' => 'required|in:ILS'];
    }
}
