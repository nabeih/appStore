<?php

namespace App\Actions\Inventory\OpeningStock;

use App\Actions\Catalog\CreateCategoryAction;
use App\Actions\Catalog\CreateColorAction;
use App\Actions\Catalog\CreateSizeAction;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Color;
use App\Models\Inventory;
use App\Models\OpeningStockEntry;
use App\Models\Product;
use App\Models\Size;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\OpeningWorkbook;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ImportOpeningWorkbook
{
    public function __construct(private OpeningWorkbook $workbook, private PostOpeningStockAction $post) {}

    /** @param array{branch_id:int, opening_date:string, currency:string} $input
     * @return array<string, mixed>
     */
    public function preview(UploadedFile $file, array $input, User $actor): array
    {
        $data = $this->validate($file, $input, $actor);

        return ['rows' => array_slice($data['rows'], 0, 100), 'row_count' => count($data['rows']), 'total_quantity' => $data['quantity'],
            'total_cost' => Money::format($data['total']), 'documents' => (int) ceil(count($data['rows']) / 300),
            'token' => Crypt::encryptString(json_encode(['hash' => $data['hash'], 'actor' => $actor->id, 'expires' => now()->addHour()->timestamp], JSON_THROW_ON_ERROR))];
    }

    /** @return list<OpeningStockEntry> */
    public function save(UploadedFile $file, array $input, string $token, User $actor): array
    {
        $data = $this->validate($file, $input, $actor);
        try {
            $proof = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(409, 'المعاينة غير صالحة؛ أعد معاينة الملف.');
        }
        abort_unless(($proof['actor'] ?? null) === $actor->id && ($proof['expires'] ?? 0) >= now()->timestamp
            && hash_equals($data['hash'], $proof['hash'] ?? ''), 409, 'تغير الملف أو الفرع أو التاريخ أو انتهت المعاينة؛ أعد المراجعة.');

        return OperationContext::run(function () use ($data, $input, $actor): array {
            $cache = [];
            $documents = [];
            foreach (array_chunk($data['rows'], 300) as $index => $rows) {
                $uuid = $this->documentUuid($data['hash'], $index);
                if ($existing = OpeningStockEntry::where('client_uuid', $uuid)->first()) {
                    abort_unless($existing->branch_id === (int) $input['branch_id'], 409);
                    abort_unless(AuditEvent::where('subject_type', $existing->getMorphClass())->where('subject_id', $existing->id)
                        ->where('action', 'opening.excel_imported')->where('data->file_hash', $data['hash'])->exists(), 409, 'معرّف الاستيراد مستخدم لمستند آخر؛ لم يتغير أي مستند.');
                    $documents[] = $existing;

                    continue;
                }
                $this->checkCatalog($rows, (int) $input['branch_id']);
                $mapped = [];
                foreach ($rows as $row) {
                    $mapped[] = ['name' => $row['product_name'], 'code' => $row['product_code'] ?: null,
                        'category_id' => $this->catalog(Category::class, $row['category'], $cache),
                        'color_id' => $this->catalog(Color::class, $row['color'], $cache),
                        'size_id' => $this->catalog(Size::class, $row['size'], $cache),
                        'qty' => (int) $row['opening_quantity'], 'cost' => $row['unit_cost'], 'price' => $row['selling_price']];
                }
                $entry = $this->post->execute(['client_uuid' => $uuid, 'branch_id' => (int) $input['branch_id'], 'opening_date' => $input['opening_date'],
                    'notes' => 'استيراد Excel موحد؛ راجع واعتمد من مسار البضاعة الافتتاحية.', 'rows' => $mapped], $actor->id, true);
                OperationContext::audit($entry, $actor, 'opening.excel_imported', 'حفظ مسودة بعد معاينة Excel',
                    ['file_hash' => $data['hash'], 'batch' => $index + 1, 'zero_cost_rows' => array_keys(array_filter($rows, fn ($row) => Money::minor($row['unit_cost']) === 0))]);
                $documents[] = $entry;
            }

            return $documents;
        });
    }

    /** @return array<string, mixed> */
    private function validate(UploadedFile $file, array $input, User $actor): array
    {
        foreach (['inventory.correct_setup', 'inventory.manage_catalog', 'sales.view_cost'] as $ability) {
            Gate::forUser($actor)->authorize($ability);
        }
        Validator::make($input, ['branch_id' => 'required|integer|min:1', 'opening_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'currency' => 'required|in:ILS'])->validate();
        $branch = OperationContext::branch($actor, (int) $input['branch_id'], 'inventory.correct_setup');
        abort_if($branch->opening_stock_closed_at, 422, 'أُغلق إدخال أول المدة لهذا الفرع؛ استخدم المشتريات الجديدة.');
        OperationContext::date($branch, $input['opening_date'], false);
        if (strtolower($file->getClientOriginalExtension()) !== 'xlsx' || $file->getSize() > 4 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'ارفع XLSX بحجم لا يتجاوز 4MB. ملفات SQL غير مسموحة.']);
        }
        $rows = $this->workbook->read($file->getRealPath());
        $seen = [];
        $total = $quantity = 0;
        foreach ($rows as $index => &$row) {
            $row['product_code'] = strtoupper($row['product_code']);
            $money = ['required', 'regex:/\A\d{1,7}(?:\.\d{1,2})?\z/'];
            $validator = Validator::make($row, ['product_name' => 'required|string|max:200', 'category' => 'required|string|max:100',
                'color' => 'required|string|max:50', 'size' => 'required|string|max:50', 'opening_quantity' => ['required', 'regex:/\A[1-9]\d{0,6}\z/', 'integer', 'max:1000000'],
                'unit_cost' => $money, 'selling_price' => $money, 'product_code' => ['nullable', 'regex:/\A[A-Z0-9_-]{1,50}\z/'], 'zero_cost_confirmed' => ['nullable', 'in:1,yes,نعم']]);
            if ($validator->fails()) {
                throw ValidationException::withMessages(['file' => 'السطر '.($index + 2).': '.implode(' ', $validator->errors()->all())]);
            }
            if (Money::minor($row['unit_cost']) === 0 && ! in_array($row['zero_cost_confirmed'], ['1', 'yes', 'نعم'], true)) {
                throw ValidationException::withMessages(['file' => 'السطر '.($index + 2).': تكلفة صفر تحتاج zero_cost_confirmed=1؛ التكلفة الفارغة مرفوضة.']);
            }
            $key = mb_strtolower(implode('|', [$row['category'], $row['product_name'], $row['color'], $row['size']]));
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['file' => 'السطر '.($index + 2).': متغير مكرر؛ اجمع الكمية في سطر واحد بعد مطابقة التكلفة.']);
            }
            $seen[$key] = true;
            $quantity += (int) $row['opening_quantity'];
            $total = Money::add($total, Money::multiply(Money::minor($row['unit_cost']), (int) $row['opening_quantity']));
        }
        unset($row);
        $hash = OperationContext::hash(['branch_id' => (int) $input['branch_id'], 'opening_date' => $input['opening_date'], 'currency' => $input['currency'], 'rows' => $rows]);
        $newRows = [];
        foreach (array_chunk($rows, 300) as $index => $chunk) {
            $documentTotal = Money::sum(array_map(fn ($row) => Money::multiply(Money::minor($row['unit_cost']), (int) $row['opening_quantity']), $chunk));
            if ($documentTotal > 99999999999999) {
                throw ValidationException::withMessages(['file' => 'قيمة المستند '.($index + 1).' تتجاوز النطاق المالي المدعوم؛ قسّم البيانات إلى ملفات أصغر دون تغيير التكلفة.']);
            }
            if (! OpeningStockEntry::where('client_uuid', $this->documentUuid($hash, $index))->exists()) {
                array_push($newRows, ...$chunk);
            }
        }
        $this->checkCatalog($newRows, (int) $input['branch_id']);

        return ['rows' => $rows, 'quantity' => $quantity, 'total' => $total, 'hash' => $hash];
    }

    private function documentUuid(string $hash, int $index): string
    {
        $value = hash('sha256', $hash.':'.$index);

        return substr($value, 0, 8).'-'.substr($value, 8, 4).'-5'.substr($value, 13, 3).'-8'.substr($value, 17, 3).'-'.substr($value, 20, 12);
    }

    /** @param list<array<string, string>> $rows */
    private function checkCatalog(array $rows, int $branchId): void
    {
        if ($rows === []) {
            return;
        }
        $masters = [];
        foreach ([Category::class => 'category', Color::class => 'color', Size::class => 'size'] as $class => $column) {
            $masters[$column] = $class::whereIn('name', array_unique(array_column($rows, $column)))->get()->groupBy(fn ($model) => mb_strtolower($model->name));
        }
        $products = Product::withTrashed()->with('variants')->where(fn ($query) => $query->whereIn('code', array_filter(array_column($rows, 'product_code')))
            ->orWhereIn('name', array_unique(array_column($rows, 'product_name'))))->get();
        $variantIds = $products->flatMap(fn ($product) => $product->variants->modelKeys());
        $occupied = Inventory::where('branch_id', $branchId)->whereIn('product_variant_id', $variantIds)->pluck('product_variant_id')->flip();
        $history = StockMovement::where('branch_id', $branchId)->whereIn('product_variant_id', $variantIds)->pluck('product_variant_id')->flip();
        foreach ($rows as $row) {
            $ids = [];
            foreach ($masters as $column => $names) {
                $matches = $names->get(mb_strtolower($row[$column]), collect());
                if ($matches->count() > 1 || ($matches->isNotEmpty() && ! $matches->first()->is_active)) {
                    throw ValidationException::withMessages(['file' => 'اسم ملتبس أو غير فعّال في الكتالوج: '.$row[$column].'؛ راجعه قبل الاستيراد.']);
                }
                $ids[$column] = $matches->first()?->id;
            }
            $matches = $row['product_code'] !== '' ? $products->filter(fn ($product) => strtoupper($product->code) === $row['product_code'])
                : $products->filter(fn ($product) => mb_strtolower($product->name) === mb_strtolower($row['product_name']) && $product->category_id === $ids['category']);
            $product = $matches->first();
            if ($matches->count() > 1 || ($product && ($product->trashed() || ! $product->is_active || $product->category_id !== $ids['category'] || mb_strtolower($product->name) !== mb_strtolower($row['product_name'])))) {
                throw ValidationException::withMessages(['file' => 'هوية المنتج غير متطابقة أو ملتبسة أو غير فعّالة: '.$row['product_name']]);
            }
            $variant = $product?->variants->first(fn ($variant) => $variant->color_id === $ids['color'] && $variant->size_id === $ids['size']);
            if ($variant && (! $variant->is_active || $occupied->has($variant->id) || $history->has($variant->id)
                || Money::minor($variant->selling_price) !== Money::minor($row['selling_price']))) {
                throw ValidationException::withMessages(['file' => 'للصنف رصيد أو حركة سابقة، أو سعر/حالة مختلفان: '.$row['product_name'].' / '.$row['color'].' / '.$row['size'].'؛ لا يغير الاستيراد تاريخ الصنف أو سعره.']);
            }
        }
    }

    /** @param class-string<Category|Color|Size> $class
     * @param  array<string, int>  $cache
     */
    private function catalog(string $class, string $name, array &$cache): int
    {
        $key = $class.':'.mb_strtolower($name);
        if (! isset($cache[$key])) {
            $matches = $class::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->get();
            if ($matches->count() > 1 || ($matches->isNotEmpty() && ! $matches->first()->is_active)) {
                throw ValidationException::withMessages(['file' => 'اسم تصنيف أو لون أو مقاس ملتبس أو غير فعّال: '.$name.'؛ راجعه في الكتالوج.']);
            }
            $model = $matches->first() ?? match ($class) {
                Category::class => app(CreateCategoryAction::class)->execute(['name' => $name]),
                Color::class => app(CreateColorAction::class)->execute(['name' => $name]),
                Size::class => app(CreateSizeAction::class)->execute(['name' => $name]),
            };
            $cache[$key] = $model->id;
        }

        return $cache[$key];
    }
}
