<?php

namespace App\Actions\Inventory\OpeningStock;

use App\Models\Branch;
use App\Models\Inventory;
use App\Models\OpeningStockEntry;
use App\Models\OpeningStockItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Support\Inventory\OpeningStock\DecimalAmount;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class PostOpeningStockAction
{
    public function __construct(private PrepareOpeningStockAction $prepare) {}

    public function execute(array $data, ?int $actorId = null): OpeningStockEntry
    {
        $savedFiles = [];
        $hash = $this->fingerprint($data);
        try {
            return DB::transaction(function () use ($data, $actorId, $hash, &$savedFiles): OpeningStockEntry {
                // One shared lock protects product identity/code allocation across branches.
                $counter = DB::table('product_code_counters')->where('id', 1)->lockForUpdate()->first();
                Branch::whereKey($data['branch_id'])->where('is_active', true)->lockForUpdate()->firstOrFail();
                $existing = OpeningStockEntry::where('client_uuid', $data['client_uuid'])->first();
                if ($existing) {
                    if (! hash_equals((string) $existing->request_hash, $hash)) {
                        abort(409, 'معرّف العملية مستخدم لبيانات مختلفة. افتح مستندًا جديدًا.');
                    }

                    return $existing;
                }
                $plan = $this->prepare->execute($data, true);
                $groups = $plan['groups'];
                $needed = collect($groups)->filter(fn (array $group): bool => ! $group['product'] && blank($group['code']))->count();
                $generated = $this->generateCodes($needed, (int) ($counter?->last_number ?? 0), collect($groups)->pluck('code')->filter()->all());
                foreach ($groups as &$group) {
                    if (! $group['product']) {
                        $group['product'] = Product::create([
                            'category_id' => $group['category_id'], 'name' => $group['name'],
                            'code' => filled($group['code']) ? $group['code'] : array_shift($generated), 'is_active' => true,
                        ]);
                        $group['product']->setRelation('images', collect());
                    }
                }
                unset($group);
                $entry = OpeningStockEntry::create([
                    'client_uuid' => $data['client_uuid'], 'request_hash' => $hash,
                    'branch_id' => $data['branch_id'], 'opening_date' => $data['opening_date'],
                    'created_by' => $actorId, 'approved_by' => $actorId, 'approved_at' => now(),
                    'status' => 'approved', 'notes' => $data['notes'] ?? null,
                    'total_quantity' => $plan['total_quantity'], 'total_cost' => $plan['total_cost'],
                ]);
                $inventories = $movements = [];
                $timestamp = now();
                foreach ($plan['rows'] as $row) {
                    $product = $groups[$row['group_key']]['product'];
                    $variant = $row['variant'] ?? ProductVariant::create([
                        'product_id' => $product->id, 'color_id' => $row['color']->id, 'size_id' => $row['size']->id,
                        'sku' => strtoupper($product->code.'-'.$row['color']->code.'-'.$row['size']->code),
                        'selling_price' => $row['selling_price'], 'is_active' => true,
                    ]);
                    $item = OpeningStockItem::create([
                        'opening_stock_entry_id' => $entry->id, 'product_variant_id' => $variant->id,
                        'product_code' => $product->code, 'product_name' => $product->name,
                        'category_name' => $row['category']->name, 'color_name' => $row['color']->name, 'size_name' => $row['size']->name,
                        'sku' => $variant->sku, 'quantity' => $row['quantity'], 'unit_cost' => $row['unit_cost'],
                        'selling_price' => $variant->selling_price, 'line_total' => $row['line_total'],
                    ]);
                    $inventories[] = ['branch_id' => $data['branch_id'], 'product_variant_id' => $variant->id, 'quantity' => $row['quantity'], 'average_cost' => $row['unit_cost'], 'created_at' => $timestamp, 'updated_at' => $timestamp];
                    $movements[] = ['branch_id' => $data['branch_id'], 'product_variant_id' => $variant->id, 'user_id' => $actorId,
                        'movement_type' => 'opening_stock', 'quantity_change' => $row['quantity'], 'unit_cost' => $row['unit_cost'],
                        'source_type' => $item->getMorphClass(), 'source_id' => $item->id, 'note' => $entry->number, 'created_at' => $timestamp];
                    if ($row['image']) {
                        $path = $row['image']->store('products/colors', 'public');
                        if (! $path) {
                            throw new \RuntimeException('تعذر تخزين صورة المنتج.');
                        }
                        $savedFiles[] = $path;
                        ProductImage::create(['product_id' => $product->id, 'color_id' => $row['color']->id, 'image_path' => $path]);
                    }
                }
                Inventory::insert($inventories);
                StockMovement::insert($movements);

                return $entry;
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($savedFiles);
            if ($exception instanceof QueryException && in_array((string) $exception->getCode(), ['23000', '23505', '40001'], true)) {
                throw ValidationException::withMessages(['rows' => 'تعارضت البيانات مع عملية أخرى أو كود مستخدم. لم يتم حفظ أي جزء من المستند؛ راجع الأصناف وأعد المحاولة.']);
            }
            throw $exception;
        }
    }

    private function fingerprint(array $data): string
    {
        $rows = array_map(function (array $row): array {
            return ['name' => $row['name'], 'code' => $row['code'] ?? null,
                'category_id' => (int) $row['category_id'], 'color_id' => (int) $row['color_id'], 'size_id' => (int) $row['size_id'],
                'qty' => (int) $row['qty'], 'cost' => DecimalAmount::format(DecimalAmount::minorUnits((string) $row['cost'])),
                'price' => DecimalAmount::format(DecimalAmount::minorUnits((string) $row['price'])),
                'image' => empty($row['image']) ? null : hash_file('sha256', $row['image']->getRealPath())];
        }, $data['rows']);

        return hash('sha256', json_encode(['branch_id' => (int) $data['branch_id'], 'opening_date' => $data['opening_date'], 'notes' => $data['notes'] ?? null, 'rows' => $rows], JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    private function generateCodes(int $count, int $lastNumber, array $reserved): array
    {
        $result = [];
        while (count($result) < $count) {
            $candidates = [];
            for ($i = 0; $i < max(16, $count); $i++) {
                $candidates[] = 'P-'.str_pad((string) ++$lastNumber, 8, '0', STR_PAD_LEFT);
            }
            $occupied = Product::withTrashed()->whereIn('code', $candidates)->pluck('code')->map(fn (string $code): string => strtoupper($code))->all();
            foreach (array_diff($candidates, $occupied, $reserved) as $candidate) {
                $result[] = $candidate;
                if (count($result) === $count) {
                    break;
                }
            }
        }
        if ($count > 0) {
            DB::table('product_code_counters')->updateOrInsert(['id' => 1], ['last_number' => $lastNumber, 'updated_at' => now()]);
        }

        return $result;
    }
}
