<?php

namespace App\Actions\Inventory\OpeningStock;

use App\Actions\Catalog\AllocateProductCodesAction;
use App\Actions\Catalog\ProcessProductImageAction;
use App\Models\Branch;
use App\Models\OpeningStockEntry;
use App\Models\OpeningStockItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Access\AccessPermissions;
use App\Support\Inventory\OpeningStock\DecimalAmount;
use App\Support\Sales\OperationContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class PostOpeningStockAction
{
    public function __construct(private PrepareOpeningStockAction $prepare) {}

    public function execute(array $data, ?int $actorId = null, bool $draft = false): OpeningStockEntry
    {
        if (! $draft) {
            $entry = $this->execute($data, $actorId, true);
            if ($entry->status !== 'draft') {
                return $entry;
            }
            abort_unless($entry->wasRecentlyCreated && $entry->version === 1, 409, 'المستند محفوظ كمسودة. افتحه وراجعه ثم اعتمده من صفحة المستند.');
            try {
                $approved = app(ManageOpeningDraftAction::class)->approve($entry, $entry->version, User::findOrFail($actorId));
                $approved->wasRecentlyCreated = $entry->wasRecentlyCreated;

                return $approved;
            } catch (Throwable $error) {
                report($error);
                $entry->setAttribute('approval_pending', true);

                return $entry;
            }
        }
        $savedFiles = [];
        $hash = $this->fingerprint($data);
        try {
            return DB::transaction(function () use ($data, $actorId, $hash, $draft, &$savedFiles): OpeningStockEntry {
                // One shared lock protects product identity/code allocation across branches.
                DB::table('product_code_counters')->where('id', 1)->increment('lock_version');
                if ($actorId) {
                    OperationContext::branch(User::findOrFail($actorId), (int) $data['branch_id'], 'inventory.correct_setup');
                }
                if (collect($data['rows'])->contains(fn (array $row): bool => filled($row['minimum_selling_price'] ?? null))) {
                    abort_unless($actorId && AccessPermissions::canManageMinimumPrices(User::findOrFail($actorId)), 403);
                }
                $counter = DB::table('product_code_counters')->where('id', 1)->lockForUpdate()->first();
                $branch = Branch::whereKey($data['branch_id'])->where('is_active', true)->lockForUpdate()->firstOrFail();
                $existing = OpeningStockEntry::where('client_uuid', $data['client_uuid'])->first();
                if ($existing) {
                    if (! hash_equals((string) $existing->request_hash, $hash)) {
                        abort(409, 'معرّف العملية مستخدم لبيانات مختلفة. افتح مستندًا جديدًا.');
                    }

                    abort_unless($draft || $existing->status !== 'draft', 409, 'المستند محفوظ كمسودة. افتحه وراجعه ثم اعتمده من صفحة المستند.');

                    return $existing;
                }
                $plan = $this->prepare->execute($data, true, $branch);
                $groups = $plan['groups'];
                $needed = collect($groups)->filter(fn (array $group): bool => ! $group['product'] && blank($group['code']))->count();
                $generated = app(AllocateProductCodesAction::class)->execute($needed, (int) ($counter?->last_number ?? 0), collect($groups)->pluck('code')->filter()->all());
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
                    'created_by' => $actorId, 'approved_by' => $draft ? null : $actorId, 'approved_at' => $draft ? null : now(),
                    'status' => $draft ? 'draft' : 'approved', 'version' => 1, 'notes' => $data['notes'] ?? null,
                    'total_quantity' => $plan['total_quantity'], 'total_cost' => $plan['total_cost'],
                ]);
                if (isset($data['progressive_opening'])) {
                    OperationContext::audit($entry, User::findOrFail($actorId), 'operation.opening_snapshot_recorded', $data['progressive_opening']['evidence'], ['declaration' => $data['progressive_opening']]);
                }
                foreach ($plan['rows'] as $row) {
                    $product = $groups[$row['group_key']]['product'];
                    $variant = $row['variant'] ?? ProductVariant::create([
                        'product_id' => $product->id, 'color_id' => $row['color']->id, 'size_id' => $row['size']->id,
                        'sku' => strtoupper($product->code.'-'.$row['color']->code.'-'.$row['size']->code),
                        'selling_price' => $row['selling_price'], 'minimum_selling_price' => $row['minimum_selling_price'], 'is_active' => true,
                    ]);
                    OpeningStockItem::create([
                        'opening_stock_entry_id' => $entry->id, 'product_variant_id' => $variant->id,
                        'product_code' => $product->code, 'product_name' => $product->name,
                        'category_name' => $row['category']->name, 'color_name' => $row['color']->name, 'size_name' => $row['size']->name,
                        'sku' => $variant->sku, 'quantity' => $row['quantity'], 'unit_cost' => $row['unit_cost'],
                        'selling_price' => $variant->selling_price, 'line_total' => $row['line_total'],
                    ]);
                    if ($row['image']) {
                        $paths = app(ProcessProductImageAction::class)->upload($row['image']);
                        array_push($savedFiles, ...array_values($paths));
                        ProductImage::create(['product_id' => $product->id, 'color_id' => $row['color']->id] + $paths);
                    }
                }

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
                'image' => empty($row['image']) ? null : hash_file('sha256', $row['image']->getRealPath())]
                + (filled($row['minimum_selling_price'] ?? null) ? ['minimum_selling_price' => DecimalAmount::format(DecimalAmount::minorUnits((string) $row['minimum_selling_price']))] : []);
        }, $data['rows']);

        $payload = ['branch_id' => (int) $data['branch_id'], 'opening_date' => $data['opening_date'], 'notes' => $data['notes'] ?? null, 'rows' => $rows];
        if (isset($data['progressive_opening'])) {
            $payload['progressive_opening'] = $data['progressive_opening'];
        }

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
