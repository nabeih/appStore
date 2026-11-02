<?php

namespace Tests\Feature\Mobile;

use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Finance\PostJournalAction;
use App\Actions\Store\ManageStoreOrder;
use App\Actions\Store\PlaceStoreOrder;
use App\Actions\Store\StorePricing;
use App\Models\AuditEvent;
use App\Models\DeliveryZone;
use App\Models\Inventory;
use App\Models\MobileOperation;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\PurchaseInvoice;
use App\Models\SaleInvoice;
use App\Models\StoreListing;
use App\Models\StoreSetting;
use App\Models\Supplier;
use App\Support\Mobile\MobileRevision;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MobileSynchronizationTest extends MobileTestCase
{
    public function test_bootstrap_is_paginated_and_pull_captures_raw_sql_updates_soft_delete_and_replay(): void
    {
        $variant = $this->stock();
        $this->mobileLogin();
        $initial = $this->mobile('POST', 'sync/bootstrap', ['sync_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'limit' => 2])->assertOk();
        $cursor = $initial->json('cursor');
        $seen = collect($initial->json('changes'));
        for ($page = 0; $initial->json('has_more') && $page < 100; $page++) {
            $initial = $this->mobile('POST', 'sync/pull', ['cursor' => $cursor, 'limit' => 2])->assertOk();
            $seen = $seen->merge($initial->json('changes'));
            $cursor = $initial->json('cursor');
        }
        $this->assertFalse($initial->json('has_more'));
        $this->assertTrue($seen->contains(fn ($row) => $row['entity'] === 'products' && $row['id'] === $variant->product_id));
        DB::table('products')->where('id', $variant->product_id)->update(['name' => 'Changed via raw SQL']);
        $delta = $this->mobile('POST', 'sync/pull', ['cursor' => $cursor])->assertOk();
        $this->assertSame('Changed via raw SQL', collect($delta->json('changes'))->firstWhere('entity', 'products')['data']['name']);
        $repeat = $this->mobile('POST', 'sync/pull', ['cursor' => $cursor])->assertOk();
        $this->assertSame($delta->json('changes'), $repeat->json('changes'));
        Product::findOrFail($variant->product_id)->delete();
        $deleted = $this->mobile('POST', 'sync/pull', ['cursor' => $delta->json('cursor')])->assertOk();
        $this->assertSame('delete', collect($deleted->json('changes'))->firstWhere('entity', 'products')['action']);
    }

    public function test_offline_product_then_opening_reuses_web_action_and_preserves_period_guard(): void
    {
        $payload = $this->productPayload();
        DB::table('branches')->where('id', $this->branchId)->update(['opening_stock_closed_at' => null]);
        $this->mobileLogin();
        $page = $this->mobile('POST', 'sync/bootstrap', ['sync_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'limit' => 200])->assertOk();
        while ($page->json('has_more')) {
            $page = $this->mobile('POST', 'sync/pull', ['cursor' => $page->json('cursor'), 'limit' => 200])->assertOk();
        }
        $product = $this->operation('product.create', $payload);
        $opening = $this->operation('opening_stock.create', ['opening_date' => now()->toDateString(),
            'rows' => [['variant_uuid' => $payload['variants'][0]['client_uuid'], 'qty' => 3, 'cost' => '20.00', 'price' => '35.00']]], [$product['operation_uuid']]);
        $response = $this->push([$opening, $product]);
        $byId = collect($response->json('results'))->keyBy('operation_uuid');
        $this->assertSame('accepted', $byId[$product['operation_uuid']]['status']);
        $this->assertSame('accepted', $byId[$opening['operation_uuid']]['status']);
        $id = $byId[$product['operation_uuid']]['result']['variants'][0]['id'];
        $stock = Inventory::where('product_variant_id', $id)->firstOrFail();
        $this->assertSame(3, (int) $stock->quantity);
        $this->assertSame(6000, (int) $stock->value_minor);
        $sale = $this->operation('sale.create', ['payment_mode' => 'credit', 'customer_id' => $this->customer->id,
            'rows' => [['variant_uuid' => $payload['variants'][0]['client_uuid'], 'quantity' => 1, 'expected_revision' => MobileRevision::of($stock)]]], [$opening['operation_uuid']]);
        $this->push([$sale])->assertJsonPath('results.0.status', 'validation_failed')->assertJsonMissingPath('results.0.result.id');
        $this->assertSame(3, (int) $stock->fresh()->quantity);
        $this->assertDatabaseCount('sale_invoices', 0);
    }

    public function test_offline_product_purchase_and_partial_sale_are_atomic_and_replay_without_duplicate_postings(): void
    {
        $payload = $this->productPayload();
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => '100.00', 'reason' => 'نقد فعلي للاختبار المعزول'], $this->owner);
        $supplier = Supplier::factory()->create();
        $this->mobileLogin();
        $page = $this->mobile('POST', 'sync/bootstrap', ['sync_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'limit' => 200])->assertOk();
        while ($page->json('has_more')) {
            $page = $this->mobile('POST', 'sync/pull', ['cursor' => $page->json('cursor'), 'limit' => 200])->assertOk();
        }
        $product = $this->operation('product.create', $payload);
        $purchase = $this->operation('purchase.create', ['supplier_id' => $supplier->id, 'invoice_date' => now()->toDateString(),
            'initial_payment' => '20.00', 'payment_method' => 'cash', 'payment_details' => ['account_id' => $this->cash->id, 'confirmed' => true],
            'rows' => [['variant_uuid' => $payload['variants'][0]['client_uuid'], 'quantity' => 4, 'unit_cost' => '20.00']]], [$product['operation_uuid']]);
        $sale = $this->operation('sale.create', ['customer_id' => $this->customer->id, 'payment_mode' => 'partial',
            'payments' => [['account_id' => $this->cash->id, 'amount' => '10.00', 'confirmed' => true]],
            'rows' => [['variant_uuid' => $payload['variants'][0]['client_uuid'], 'quantity' => 1, 'stock_revision_from' => $purchase['operation_uuid']]]], [$purchase['operation_uuid']]);
        $response = $this->push([$sale, $purchase, $product]);
        $results = collect($response->json('results'))->keyBy('operation_uuid');
        foreach ([$product, $purchase, $sale] as $operation) {
            $this->assertSame('accepted', $results[$operation['operation_uuid']]['status'], json_encode($results[$operation['operation_uuid']]));
        }
        $id = $results[$product['operation_uuid']]['result']['variants'][0]['id'];
        $this->assertDatabaseHas('inventories', ['product_variant_id' => $id, 'branch_id' => $this->branchId, 'quantity' => 3, 'value_minor' => 6000]);
        $this->assertSame(2500, (int) SaleInvoice::findOrFail($results[$sale['operation_uuid']]['result']['id'])->due_minor);
        $this->assertSame(9000, app(PostJournalAction::class)->balance($this->cash->id));
        $this->assertSame(0, (int) DB::table('journal_lines')->sum('debit_minor') - (int) DB::table('journal_lines')->sum('credit_minor'));
        $debt = $this->mobile('GET', 'data/customer_balances?branch_id='.$this->branchId)->assertOk();
        $this->assertSame(2500, (int) collect($debt->json('data'))->firstWhere('id', $this->customer->id)['debt_minor']);
        $counts = [DB::table('journal_entries')->count(), DB::table('stock_movements')->count(), DB::table('sale_invoices')->count(), DB::table('supplier_payments')->count()];
        $replay = $this->push([$sale, $purchase, $product])->json('results');
        $this->assertSame(['already_applied', 'already_applied', 'already_applied'], array_column($replay, 'status'));
        $this->assertSame($counts, [DB::table('journal_entries')->count(), DB::table('stock_movements')->count(), DB::table('sale_invoices')->count(), DB::table('supplier_payments')->count()]);
        $delta = $this->mobile('POST', 'sync/pull', ['cursor' => $page->json('cursor'), 'limit' => 200])->assertOk();
        $stockChange = collect($delta->json('changes'))->first(fn (array $row): bool => $row['entity'] === 'stocks' && (int) ($row['data']['product_variant_id'] ?? 0) === (int) $id);
        $this->assertNotNull($stockChange);
        $this->assertSame(3, $stockChange['data']['quantity']);
        $this->assertSame(3, $stockChange['data']['available_quantity']);
        $this->assertSame(6000, $stockChange['data']['value_minor']);
    }

    public function test_http_web_and_mobile_purchase_and_sale_have_matching_stock_values_and_financial_totals(): void
    {
        $webVariant = $this->stock('35', '20', 10, 'PARITY-WEB');
        $apiVariant = $this->stock('35', '20', 10, 'PARITY-API');
        $supplier = Supplier::factory()->create();
        $purchasePayload = ['supplier_id' => $supplier->id, 'invoice_date' => now()->toDateString(), 'initial_payment' => '0.00', 'payment_method' => 'cash',
            'rows' => [['product_variant_id' => $webVariant->id, 'quantity' => 2, 'unit_cost' => '40.00']]];
        $web = $this->postJson(route('purchases.invoices.store'), $purchasePayload + ['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId])->assertOk();
        $this->postJson(route('purchases.invoices.approve', $web->json('id')), ['version' => $web->json('version')])->assertOk();
        $webSale = $this->postJson(route('sales.invoices.complete'), ['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId,
            'customer_id' => $this->customer->id, 'payment_mode' => 'credit', 'rows' => [['product_variant_id' => $webVariant->id, 'quantity' => 2]]])->assertOk();
        $this->mobileLogin();
        $purchasePayload['rows'][0]['product_variant_id'] = $apiVariant->id;
        $purchase = $this->operation('purchase.create', $purchasePayload);
        $sale = $this->operation('sale.create', ['customer_id' => $this->customer->id, 'payment_mode' => 'credit',
            'rows' => [['product_variant_id' => $apiVariant->id, 'quantity' => 2, 'stock_revision_from' => $purchase['operation_uuid']]]], [$purchase['operation_uuid']]);
        $results = collect($this->push([$sale, $purchase])->json('results'))->keyBy('operation_uuid');
        $this->assertSame('accepted', $results[$purchase['operation_uuid']]['status']);
        $this->assertSame('accepted', $results[$sale['operation_uuid']]['status']);
        $webInvoice = SaleInvoice::findOrFail($webSale->json('id'));
        $apiInvoice = SaleInvoice::findOrFail($results[$sale['operation_uuid']]['result']['id']);
        $this->assertSame($webInvoice->only(['total_minor', 'cost_minor', 'due_minor', 'paid_minor', 'status']),
            $apiInvoice->only(['total_minor', 'cost_minor', 'due_minor', 'paid_minor', 'status']));
        $webStock = Inventory::where('product_variant_id', $webVariant->id)->firstOrFail();
        $apiStock = Inventory::where('product_variant_id', $apiVariant->id)->firstOrFail();
        $this->assertSame($webStock->only(['quantity', 'value_minor', 'average_cost']), $apiStock->only(['quantity', 'value_minor', 'average_cost']));
        $webPurchase = PurchaseInvoice::findOrFail($web->json('id'));
        $apiPurchase = PurchaseInvoice::findOrFail($results[$purchase['operation_uuid']]['result']['id']);
        $this->assertSame($webPurchase->only(['total_minor', 'paid_minor', 'status']), $apiPurchase->only(['total_minor', 'paid_minor', 'status']));
        $postings = fn (string $type, int $id): array => DB::table('journal_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('financial_accounts as accounts', 'accounts.id', '=', 'lines.financial_account_id')
            ->where('entries.source_type', $type)->where('entries.source_id', $id)->groupBy('accounts.code')->orderBy('accounts.code')
            ->select('accounts.code')->selectRaw('SUM(lines.debit_minor) AS debits, SUM(lines.credit_minor) AS credits')->get()->toArray();
        $this->assertEquals($postings(PurchaseInvoice::class, $webPurchase->id), $postings(PurchaseInvoice::class, $apiPurchase->id));
        $this->assertEquals($postings(SaleInvoice::class, $webInvoice->id), $postings(SaleInvoice::class, $apiInvoice->id));
        $movements = fn (int $variant): array => DB::table('stock_movements')->where('product_variant_id', $variant)->orderBy('id')
            ->get(['movement_type', 'quantity_change', 'value_change_minor', 'stock_bucket'])->toArray();
        $this->assertEquals($movements($webVariant->id), $movements($apiVariant->id));
        $this->assertSame(0, (int) DB::table('journal_lines')->sum('debit_minor') - (int) DB::table('journal_lines')->sum('credit_minor'));
    }

    public function test_inventory_conflict_and_audit_failure_roll_back_every_business_effect(): void
    {
        $variant = $this->stock();
        $this->mobileLogin();
        $stock = Inventory::where('product_variant_id', $variant->id)->firstOrFail();
        $operation = $this->operation('sale.create', ['customer_id' => $this->customer->id, 'payment_mode' => 'credit',
            'rows' => [['product_variant_id' => $variant->id, 'quantity' => 1, 'expected_revision' => str_repeat('0', 64)]]]);
        $this->push([$operation])->assertJsonPath('results.0.status', 'conflict_requires_review');
        $this->assertDatabaseCount('sale_invoices', 0);
        $operation['operation_uuid'] = (string) Str::uuid();
        $operation['payload']['rows'][0]['expected_revision'] = MobileRevision::of($stock);
        $counts = [DB::table('journal_entries')->count(), DB::table('stock_movements')->count()];
        Event::listen('eloquent.creating: '.AuditEvent::class, function ($event): void {
            if ($event->action === 'sale.approved') {
                throw new \RuntimeException('Isolated simulated audit failure');
            }
        });
        try {
            $this->push([$operation])->assertJsonPath('results.0.status', 'retryable');
        } finally {
            Event::forget('eloquent.creating: '.AuditEvent::class);
        }
        $this->assertDatabaseCount('sale_invoices', 0);
        $this->assertSame($counts, [DB::table('journal_entries')->count(), DB::table('stock_movements')->count()]);
        $this->assertSame((int) $stock->quantity, (int) $stock->fresh()->quantity);
        $this->push([$operation])->assertJsonPath('results.0.status', 'accepted');
        $this->assertDatabaseCount('sale_invoices', 1);
    }

    public function test_product_revision_prevents_lost_updates_and_cursor_binding_prevents_permission_scope_reuse(): void
    {
        $payload = $this->productPayload();
        $this->mobileLogin();
        $created = $this->push([$this->operation('product.create', $payload)])->json('results.0.result');
        $update = $this->operation('product.update', ['product_id' => $created['product_id'], 'expected_revision' => $created['revision'], 'name' => 'اسم محدث']);
        $this->push([$update])->assertJsonPath('results.0.status', 'accepted');
        $update['operation_uuid'] = (string) Str::uuid();
        $update['payload']['name'] = 'تعديل متقادم';
        $this->push([$update])->assertJsonPath('results.0.status', 'conflict_requires_review');
        $initial = $this->mobile('POST', 'sync/bootstrap', ['sync_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId])->assertOk();
        $cursor = $initial->json('cursor');
        $this->owner->role->permissions()->detach(Permission::where('name', 'sales.view_cost')->value('id'));
        $this->mobile('POST', 'sync/pull', ['cursor' => $cursor])->assertStatus(409);
        $this->mobile('POST', 'sync/pull', ['cursor' => 'tampered-token'])->assertUnprocessable()->assertJsonMissingPath('exception');
    }

    public function test_image_upload_is_local_webp_idempotent_and_cleans_files_after_rollback(): void
    {
        Storage::fake('public');
        $variant = $this->stock();
        $this->mobileLogin();
        $image = UploadedFile::fake()->image('phone.jpg', 1400, 1000);
        $input = ['operation_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'product_id' => $variant->product_id,
            'color_id' => $variant->color_id, 'schema_version' => 1, 'image' => $image];
        $result = $this->mobile('POST', 'product-images', $input)->assertOk()->assertJsonPath('status', 'accepted')->json('result');
        $this->mobile('POST', 'product-images', $input)->assertOk()->assertJsonPath('status', 'already_applied');
        $this->assertSame(2, count(Storage::disk('public')->allFiles()));
        $record = ProductImage::findOrFail($result['image_id']);
        foreach ([$record->image_path => 1200, $record->thumbnail_path => 160] as $path => $max) {
            $details = getimagesize(Storage::disk('public')->path($path));
            $this->assertSame('image/webp', $details['mime']);
            $this->assertLessThanOrEqual($max, max($details[0], $details[1]));
        }
        $input['image'] = UploadedFile::fake()->image('different.jpg');
        $this->mobile('POST', 'product-images', $input)->assertStatus(409);
        $this->assertDatabaseCount('product_images', 1);
        $other = $this->stock('35', '20', 1, 'IMAGE-ROLLBACK');
        $input['operation_uuid'] = (string) Str::uuid();
        $input['product_id'] = $other->product_id;
        Event::listen('eloquent.creating: '.AuditEvent::class, function ($event): void {
            if ($event->action === 'mobile.image.upload') {
                throw new \RuntimeException('Isolated image audit failure');
            }
        });
        try {
            $this->mobile('POST', 'product-images', $input)->assertStatus(503)->assertJsonMissingPath('exception');
        } finally {
            Event::forget('eloquent.creating: '.AuditEvent::class);
        }
        $this->assertDatabaseCount('product_images', 1);
        $this->assertCount(2, Storage::disk('public')->allFiles());
        $this->mobile('POST', 'product-images', $input)->assertOk()->assertJsonPath('status', 'accepted');
        $this->assertCount(4, Storage::disk('public')->allFiles());
    }

    public function test_dependency_failure_persists_and_blocks_business_mutations(): void
    {
        $this->mobileLogin();
        $product = $this->operation('product.create', ['client_uuid' => (string) Str::uuid(), 'name' => 'invalid']);
        $child = $this->operation('sale.create', ['rows' => []], [$product['operation_uuid']]);
        $this->push([$child, $product])->assertJsonPath('results.0.status', 'validation_failed')->assertJsonPath('results.1.status', 'dependency_blocked');
        $this->assertDatabaseCount('sale_invoices', 0);
        $this->assertSame(2, MobileOperation::count());
    }

    public function test_last_reserved_piece_cannot_be_sold_by_mobile_or_pos_even_with_current_revision(): void
    {
        $variant = $this->stock('35.00', '20.00', 1, 'RELEASE-LAST');
        $settings = StoreSetting::factory()->create(['branch_id' => $this->branchId]);
        $zone = DeliveryZone::factory()->create();
        StoreListing::factory()->create(['product_id' => $variant->product_id]);
        $data = ['client_uuid' => (string) Str::uuid(), 'customer_name' => 'عميل اختبار', 'phone' => '0599000123', 'address' => 'عنوان اختبار معزول',
            'delivery_zone_id' => $zone->id, 'accepted' => true, 'policy_version' => hash('sha256', $settings->policy),
            'rows' => [['product_variant_id' => $variant->id, 'quantity' => 1]]];
        $pricing = app(StorePricing::class);
        $data['quote_revision'] = $pricing->revision($pricing->quote($data));
        $order = app(PlaceStoreOrder::class)->execute($data);
        app(ManageStoreOrder::class)->transition($order, ['operation_uuid' => (string) Str::uuid(), 'version' => 1, 'action' => 'confirm', 'reason' => 'اختبار حجز آخر قطعة'], $this->owner);
        $stock = Inventory::where('product_variant_id', $variant->id)->firstOrFail();
        $counts = [DB::table('journal_entries')->count(), DB::table('stock_movements')->count()];
        $this->mobileLogin();
        $this->mobile('GET', 'data/stocks/'.$stock->id.'?branch_id='.$this->branchId)
            ->assertOk()->assertJsonPath('data.quantity', 1)->assertJsonPath('data.reserved_quantity', 1)->assertJsonPath('data.available_quantity', 0);
        $sale = $this->operation('sale.create', ['customer_id' => $this->customer->id, 'payment_mode' => 'credit',
            'rows' => [['product_variant_id' => $variant->id, 'quantity' => 1, 'expected_revision' => MobileRevision::of($stock->fresh())]]]);
        $this->push([$sale])->assertJsonPath('results.0.status', 'validation_failed');
        Auth::forgetGuards();
        $this->actingAs($this->owner, 'web')->postJson(route('sales.invoices.complete'), $this->saleData($variant,
            ['rows' => [['product_variant_id' => $variant->id, 'quantity' => 1]]]))->assertUnprocessable();
        $this->assertDatabaseCount('sale_invoices', 0);
        $this->assertSame($counts, [DB::table('journal_entries')->count(), DB::table('stock_movements')->count()]);
        $this->assertSame(1, (int) $stock->fresh()->quantity);
        $this->assertSame(1, (int) $stock->fresh()->reserved_quantity);
    }

    public function test_order_reservation_changes_stock_revision_and_incremental_available_quantity(): void
    {
        $variant = $this->stock();
        $settings = StoreSetting::factory()->create(['branch_id' => $this->branchId]);
        $zone = DeliveryZone::factory()->create();
        StoreListing::factory()->create(['product_id' => $variant->product_id]);
        $data = ['client_uuid' => (string) Str::uuid(), 'customer_name' => 'عميل اختبار', 'phone' => '0599000123', 'address' => 'عنوان تجريبي',
            'delivery_zone_id' => $zone->id, 'accepted' => true, 'policy_version' => hash('sha256', $settings->policy),
            'rows' => [['product_variant_id' => $variant->id, 'quantity' => 1]]];
        $pricing = app(StorePricing::class);
        $data['quote_revision'] = $pricing->revision($pricing->quote($data));
        $order = app(PlaceStoreOrder::class)->execute($data);
        $this->mobileLogin();
        $page = $this->mobile('POST', 'sync/bootstrap', ['sync_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'limit' => 200])->assertOk();
        while ($page->json('has_more')) {
            $page = $this->mobile('POST', 'sync/pull', ['cursor' => $page->json('cursor'), 'limit' => 200])->assertOk();
        }
        $stock = Inventory::where('product_variant_id', $variant->id)->firstOrFail();
        $oldRevision = MobileRevision::of($stock);
        app(ManageStoreOrder::class)->transition($order, ['operation_uuid' => (string) Str::uuid(), 'version' => 1, 'action' => 'confirm', 'reason' => 'حجز اختبار مزامنة'], $this->owner);
        $delta = $this->mobile('POST', 'sync/pull', ['cursor' => $page->json('cursor'), 'limit' => 200])->assertOk();
        $updated = collect($delta->json('changes'))->first(fn (array $row): bool => $row['entity'] === 'stocks' && $row['id'] === $stock->id);
        $this->assertSame(1, $updated['data']['reserved_quantity']);
        $this->assertSame(9, $updated['data']['available_quantity']);
        $this->assertNotSame($oldRevision, $updated['data']['revision']);
        $sale = $this->operation('sale.create', ['customer_id' => $this->customer->id, 'payment_mode' => 'credit',
            'rows' => [['product_variant_id' => $variant->id, 'quantity' => 1, 'expected_revision' => $oldRevision]]]);
        $this->push([$sale])->assertJsonPath('results.0.status', 'conflict_requires_review');
    }
}
