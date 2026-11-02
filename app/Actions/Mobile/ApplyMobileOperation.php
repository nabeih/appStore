<?php

namespace App\Actions\Mobile;

use App\Actions\Catalog\AllocateProductCodesAction;
use App\Actions\Catalog\CreateProductAction;
use App\Actions\Catalog\UpdateProductAction;
use App\Actions\Inventory\OpeningStock\PostOpeningStockAction;
use App\Actions\Purchases\ApprovePurchaseInvoiceAction;
use App\Actions\Purchases\SavePurchaseInvoiceAction;
use App\Actions\Sales\PostSaleAction;
use App\Actions\Sales\SaveSaleAction;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Http\Requests\Inventory\OpeningStock\StoreOpeningStockRequest;
use App\Http\Requests\Purchases\SavePurchaseInvoiceRequest;
use App\Http\Requests\Sales\SaveSaleRequest;
use App\Models\Inventory;
use App\Models\MobileDevice;
use App\Models\MobileEntityMapping;
use App\Models\MobileOperation;
use App\Models\OpeningStockEntry;
use App\Models\ProductVariant;
use App\Models\PurchaseInvoice;
use App\Models\SaleInvoice;
use App\Models\User;
use App\Support\Mobile\MobileRevision;
use App\Support\Sales\OperationContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ApplyMobileOperation
{
    public function execute(User $actor, MobileDevice $device, array $operation): array
    {
        $type = $operation['operation_type'];
        $branch = $operation['branch_id'];
        app(MobileAccess::class)->operation($actor, $type, $branch);

        return match ($type) {
            'product.create', 'product.update' => $this->product($actor, $device, $operation),
            'opening_stock.create', 'purchase.create', 'sale.create' => $this->document($actor, $device, $operation),
            default => abort(422),
        };
    }

    private function validate(FormRequest $request, array $data, User $actor, ?int $id = null): array
    {
        $request->replace($data);
        $request->setContainer(app())->setUserResolver(fn () => $actor);
        if ($id !== null) {
            $route = new Route('PUT', 'mobile/{id}', []);
            $route->bind(Request::create('/mobile/'.$id, 'PUT'));
            $request->setRouteResolver(fn () => $route);
        }
        $validator = Validator::make($data, $request->rules());
        if (method_exists($request, 'after')) {
            foreach ($request->after() as $after) {
                $validator->after($after);
            }
        }

        return $validator->validate();
    }

    private function keys(array $payload, array $allowed): void
    {
        abort_if(array_diff(array_keys($payload), $allowed) !== [], 422, 'حقول غير مدعومة في العملية.');
    }

    private function uuid(mixed $uuid): void
    {
        Validator::make(['uuid' => $uuid], ['uuid' => ['required', 'uuid']])->validate();
    }

    private function mapped(MobileDevice $device, string $entity, string $uuid): int
    {
        $this->uuid($uuid);
        $id = MobileEntityMapping::where('device_id', $device->id)->where('entity', $entity)->where('client_uuid', $uuid)->value('server_id');
        abort_unless($id, 409, 'لم تكتمل مزامنة المرجع المحلي.');

        return (int) $id;
    }

    private function map(MobileDevice $device, string $entity, string $uuid, int $id): void
    {
        $this->uuid($uuid);
        $mapping = MobileEntityMapping::firstOrCreate(['device_id' => $device->id, 'entity' => $entity, 'client_uuid' => $uuid], ['server_id' => $id]);
        abort_unless((int) $mapping->server_id === $id, 409, 'المرجع المحلي مرتبط بسجل آخر.');
    }

    private function product(User $actor, MobileDevice $device, array $operation): array
    {
        $payload = $operation['payload'];
        $create = $operation['operation_type'] === 'product.create';
        $this->keys($payload, ['client_uuid', 'product_id', 'product_uuid', 'expected_revision', 'code', 'name', 'category_id', 'description', 'is_active', 'variants']);
        Validator::make($payload, ['product_id' => ['nullable', 'integer', 'min:1'], 'product_uuid' => ['nullable', 'uuid'],
            'client_uuid' => ['nullable', 'uuid'], 'expected_revision' => ['nullable', 'string', 'size:64']])->validate();
        abort_if(isset($payload['product_id'], $payload['product_uuid']), 422, 'استخدم مرجعًا واحدًا للمنتج.');
        $clientUuid = $payload['client_uuid'] ?? null;
        unset($payload['client_uuid']);
        $current = null;
        if ($create) {
            abort_unless(is_string($clientUuid), 422, 'معرّف المنتج المحلي مطلوب.');
            $this->uuid($clientUuid);
            abort_if(MobileEntityMapping::where('device_id', $device->id)->where('entity', 'products')->where('client_uuid', $clientUuid)->exists(), 409, 'المنتج المحلي موجود؛ استخدم عملية تعديل.');
            if (blank($payload['code'] ?? null)) {
                $counter = DB::table('product_code_counters')->where('id', 1)->first();
                $payload['code'] = app(AllocateProductCodesAction::class)->execute(1, (int) $counter->last_number, [])[0];
            }
        } else {
            $id = isset($payload['product_uuid']) ? $this->mapped($device, 'products', $payload['product_uuid']) : (int) ($payload['product_id'] ?? 0);
            $current = app(ReadMobileData::class)->query($actor, $operation['branch_id'], 'products')->findOrFail($id);
            $current->load('variants');
            abort_unless(is_string($payload['expected_revision'] ?? null) && hash_equals(MobileRevision::product($current), $payload['expected_revision']), 409, 'تغير المنتج؛ راجع النسخة الحالية.');
        }
        unset($payload['product_id'], $payload['product_uuid'], $payload['expected_revision']);
        $mapping = [];
        $allowedVariantIds = $current ? app(ReadMobileData::class)->query($actor, $operation['branch_id'], 'variants')->where('product_id', $current->id)->pluck('id')->all() : [];
        $payload['variants'] ??= [];
        abort_unless(is_array($payload['variants']) && count($payload['variants']) <= 100, 422, 'الحد الأقصى مئة متغير في العملية.');
        foreach ($payload['variants'] as $index => &$row) {
            abort_unless(is_array($row), 422);
            abort_if(! $create && isset($row['id']) && ! in_array((int) $row['id'], $allowedVariantIds, true), 404);
            if (isset($row['client_uuid'])) {
                $mapping[$index] = ['uuid' => $row['client_uuid'], 'color' => $row['color_id'] ?? null, 'size' => $row['size_id'] ?? null];
                $this->uuid($row['client_uuid']);
                unset($row['client_uuid']);
            } elseif ($create || empty($row['id'])) {
                abort(422, 'معرّف محلي مطلوب لكل متغير جديد.');
            }
            if (isset($row['minimum_selling_price'])) {
                abort_unless($actor->can('sales.view_cost'), 403);
            }
        }
        unset($row);
        $data = $this->validate($create ? new StoreProductRequest : new UpdateProductRequest, $payload, $actor, $current?->id);
        $product = $create ? app(CreateProductAction::class)->execute($data) : app(UpdateProductAction::class)->execute($current, $data);
        $product->refresh()->load('variants');
        if ($create) {
            $this->map($device, 'products', $clientUuid, $product->id);
        }
        foreach ($mapping as $entry) {
            $variant = $product->variants->first(fn ($variant) => (int) $variant->color_id === (int) $entry['color'] && (int) $variant->size_id === (int) $entry['size']);
            abort_unless($variant, 422);
            $this->map($device, 'variants', $entry['uuid'], $variant->id);
        }
        OperationContext::audit($product, $actor, 'mobile.'.$operation['operation_type'], null, ['operation_uuid' => $operation['operation_uuid'], 'device_id' => $device->id]);

        $visibleVariants = app(ReadMobileData::class)->query($actor, $operation['branch_id'], 'variants', false)
            ->where('product_id', $product->id)->get(['id', 'sku']);

        return ['product_id' => $product->id, 'code' => $product->code, 'revision' => MobileRevision::product($product),
            'variants' => $visibleVariants->map(fn ($variant): array => ['id' => $variant->id, 'sku' => $variant->sku])->all(),
            'mappings' => MobileEntityMapping::where('device_id', $device->id)->where(function ($query) use ($product, $visibleVariants): void {
                $query->where(fn ($query) => $query->where('entity', 'products')->where('server_id', $product->id))
                    ->orWhere(fn ($query) => $query->where('entity', 'variants')->whereIn('server_id', $visibleVariants->modelKeys()));
            })->get(['entity', 'client_uuid', 'server_id'])->toArray()];
    }

    private function document(User $actor, MobileDevice $device, array $operation): array
    {
        $type = $operation['operation_type'];
        $payload = $operation['payload'];
        $allowed = match ($type) {
            'opening_stock.create' => ['opening_date', 'notes', 'rows'],
            'purchase.create' => ['supplier_id', 'invoice_date', 'supplier_invoice_number', 'notes', 'initial_payment', 'payment_method', 'payment_details', 'rows'],
            'sale.create' => ['customer_id', 'invoice_date', 'payment_mode', 'payments', 'discount', 'reason', 'notes', 'rows', 'below_minimum_confirmed'],
        };
        $this->keys($payload, $allowed);
        abort_unless(isset($payload['rows']) && is_array($payload['rows']) && array_is_list($payload['rows']) && count($payload['rows']) > 0 && count($payload['rows']) <= 300, 422, 'بنود المستند غير صالحة.');
        $ids = [];
        foreach ($payload['rows'] as &$row) {
            abort_unless(is_array($row), 422);
            Validator::make($row, ['variant_uuid' => ['nullable', 'uuid'], 'product_variant_id' => ['nullable', 'integer', 'min:1']])->validate();
            abort_if(isset($row['variant_uuid'], $row['product_variant_id']), 422, 'استخدم مرجعًا واحدًا للصنف.');
            $id = isset($row['variant_uuid']) ? $this->mapped($device, 'variants', $row['variant_uuid']) : (int) ($row['product_variant_id'] ?? 0);
            unset($row['variant_uuid']);
            $variant = ProductVariant::whereKey($id)->where('is_active', true)->whereHas('product', fn ($query) => $query->where('is_active', true))
                ->where(function ($query) use ($operation, $type): void {
                    $query->whereHas('inventories', fn ($query) => $query->where('branch_id', $operation['branch_id']));
                    if ($type !== 'sale.create') {
                        $query->orWhereDoesntHave('inventories');
                    }
                })->with('product')->firstOrFail();
            $ids[] = $id;
            if ($type === 'sale.create') {
                $stock = Inventory::where('branch_id', $operation['branch_id'])->where('product_variant_id', $id)->lockForUpdate()->firstOrFail();
                $expected = $row['expected_revision'] ?? null;
                if (isset($row['stock_revision_from'])) {
                    $source = $row['stock_revision_from'];
                    abort_unless(in_array($source, $operation['dependencies'], true), 422, 'مرجع المراجعة ليس من التبعيات.');
                    $expected = MobileOperation::where('device_id', $device->id)->where('operation_uuid', $source)->where('status', 'accepted')->first()?->result['stock_revisions'][(string) $id] ?? null;
                }
                abort_unless(is_string($expected) && hash_equals(MobileRevision::of($stock), $expected), 409, 'تغير رصيد المخزون؛ راجع الكمية قبل إعادة البيع.');
                unset($row['expected_revision'], $row['stock_revision_from']);
            }
            $row['product_variant_id'] = $id;
            if ($type === 'opening_stock.create') {
                $this->keys($row, ['product_variant_id', 'qty', 'cost', 'price']);
                $row = ['name' => $variant->product->name, 'code' => $variant->product->code, 'category_id' => $variant->product->category_id,
                    'color_id' => $variant->color_id, 'size_id' => $variant->size_id, 'qty' => $row['qty'] ?? null,
                    'cost' => $row['cost'] ?? null, 'price' => $row['price'] ?? $variant->selling_price];
            }
        }
        unset($row);
        $model = match ($type) {
            'opening_stock.create' => OpeningStockEntry::class,
            'purchase.create' => PurchaseInvoice::class,
            'sale.create' => SaleInvoice::class,
        };
        abort_if($model::where('client_uuid', $operation['operation_uuid'])->exists(), 409, 'المعرّف مرتبط بمستند سابق؛ راجع حالة المزامنة.');
        $payload += ['branch_id' => $operation['branch_id'], 'client_uuid' => $operation['operation_uuid']];
        $rules = match ($type) {
            'opening_stock.create' => new StoreOpeningStockRequest,
            'purchase.create' => new SavePurchaseInvoiceRequest,
            'sale.create' => new SaveSaleRequest,
        };
        $payload = $this->validate($rules, $payload, $actor);
        if ($type === 'opening_stock.create') {
            $document = app(PostOpeningStockAction::class)->execute($payload, $actor->id);
        } elseif ($type === 'purchase.create') {
            $draft = app(SavePurchaseInvoiceAction::class)->execute($payload, $actor->id);
            $document = app(ApprovePurchaseInvoiceAction::class)->execute($draft, $draft->version, $actor->id);
        } else {
            $draft = app(SaveSaleAction::class)->execute($payload, $actor);
            $document = app(PostSaleAction::class)->execute($draft, $actor);
        }
        $revisions = Inventory::where('branch_id', $operation['branch_id'])->whereIn('product_variant_id', $ids)->get()
            ->mapWithKeys(fn ($stock): array => [(string) $stock->product_variant_id => MobileRevision::of($stock)])->all();

        return ['id' => $document->id, 'number' => $document->number, 'status' => $document->status, 'stock_revisions' => $revisions];
    }
}
