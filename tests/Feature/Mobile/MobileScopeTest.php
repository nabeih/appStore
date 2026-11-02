<?php

namespace Tests\Feature\Mobile;

use App\Models\Color;
use App\Models\Inventory;
use App\Models\Permission;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use App\Support\Sales\SalesAccess;
use Database\Factories\BranchFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MobileScopeTest extends MobileTestCase
{
    private function download(array $input): array
    {
        $response = $this->mobile('POST', 'sync/bootstrap', $input)->assertOk();
        $changes = $response->json('changes');
        for ($index = 0; $response->json('has_more') && $index < 50; $index++) {
            $response = $this->mobile('POST', 'sync/pull', ['cursor' => $response->json('cursor')])->assertOk();
            array_push($changes, ...$response->json('changes'));
        }
        $this->assertFalse($response->json('has_more'));

        return ['changes' => $changes, 'cursor' => $response->json('cursor')];
    }

    public function test_shared_product_variants_images_and_initial_sync_cannot_reveal_foreign_only_metadata_or_cost(): void
    {
        $local = $this->stock('35', '20', 3, 'SHARED-MOBILE');
        $branch = BranchFactory::new()->create();
        $color = Color::create(['name' => 'FOREIGN-COLOR', 'code' => 'FC']);
        $foreign = ProductVariant::create(['product_id' => $local->product_id, 'color_id' => $color->id, 'size_id' => $local->size_id,
            'sku' => 'FOREIGN-ONLY-SKU', 'selling_price' => '432.10', 'minimum_selling_price' => '321.09', 'is_active' => true]);
        Inventory::create(['branch_id' => $branch->id, 'product_variant_id' => $foreign->id, 'quantity' => 333, 'average_cost' => '321.09', 'value_minor' => 10692297]);
        $image = ProductImage::create(['product_id' => $local->product_id, 'color_id' => $color->id, 'image_path' => 'fixture/foreign-only.webp']);
        $cashier = User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('sales_cashier')->id]);
        $this->mobileLogin($cashier);
        $this->mobile('GET', 'data/variants?branch_id='.$this->branchId.'&q=FOREIGN-ONLY-SKU')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('next_after_id', null);
        $this->mobile('GET', 'data/products?branch_id='.$this->branchId.'&q=FOREIGN-ONLY-SKU')->assertOk()->assertJsonCount(0, 'data');
        $this->mobile('GET', 'data/images/'.$image->id.'?branch_id='.$this->branchId)->assertNotFound();
        $snapshot = $this->download(['sync_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId]);
        $body = json_encode($snapshot['changes'], JSON_THROW_ON_ERROR);
        foreach (['FOREIGN-ONLY-SKU', '432.10', '321.09', 'fixture/foreign-only', 'average_cost', 'value_minor', 'cost_minor'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
        $this->assertSame([$local->id], array_column(array_filter($snapshot['changes'], fn ($row) => $row['entity'] === 'variants'), 'id'));
        $this->mobile('POST', 'sync/push', ['schema_version' => 1, 'operations' => [$this->operation('sale.create', ['rows' => []], [], $branch->id)]])->assertForbidden();
    }

    public function test_product_update_receipt_hides_foreign_variants_for_branch_limited_catalog_staff(): void
    {
        $local = $this->stock();
        $branch = BranchFactory::new()->create();
        $color = Color::create(['name' => 'Other catalog color', 'code' => 'OCC']);
        $foreign = ProductVariant::create(['product_id' => $local->product_id, 'color_id' => $color->id, 'size_id' => $local->size_id,
            'sku' => 'FOREIGN-RESULT-SECRET', 'selling_price' => '75.00', 'is_active' => true]);
        Inventory::create(['branch_id' => $branch->id, 'product_variant_id' => $foreign->id, 'quantity' => 4, 'average_cost' => '20', 'value_minor' => 8000]);
        $role = Role::create(['name' => 'catalog-local-only', 'display_name' => 'Catalog local', 'is_active' => true]);
        $role->permissions()->sync(Permission::whereIn('name', ['catalog.search', 'inventory.view', 'inventory.manage_catalog'])->pluck('id'));
        $actor = User::factory()->create(['role_id' => $role->id, 'branch_id' => $this->branchId]);
        $this->mobileLogin($actor);
        $current = $this->mobile('GET', 'data/products/'.$local->product_id.'?branch_id='.$this->branchId)->assertOk()->json('data');
        $operation = $this->operation('product.update', ['product_id' => $local->product_id, 'expected_revision' => $current['revision'], 'name' => 'Scoped catalog update']);
        $response = $this->push([$operation])->assertJsonPath('results.0.status', 'accepted')->assertJsonCount(1, 'results.0.result.variants');
        $this->assertStringNotContainsString('FOREIGN-RESULT-SECRET', $response->getContent());
        $this->mobile('GET', 'sync/operations/'.$operation['operation_uuid'])->assertOk()->assertDontSee('FOREIGN-RESULT-SECRET');
        $this->push([$operation])->assertJsonPath('results.0.status', 'already_applied')->assertDontSee('FOREIGN-RESULT-SECRET');
    }

    public function test_foreign_activity_does_not_appear_in_incremental_records_pagination_or_tombstones(): void
    {
        $this->stock();
        $foreign = $this->stock('35', '20', 2, 'FOREIGN-EVENT');
        $branch = BranchFactory::new()->create();
        Inventory::where('product_variant_id', $foreign->id)->update(['branch_id' => $branch->id]);
        $this->mobileLogin(User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('sales_cashier')->id]));
        $snapshot = $this->download(['sync_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId]);
        DB::table('products')->where('id', $foreign->product_id)->update(['name' => 'FOREIGN-ACTIVITY-ONLY']);
        $response = $this->mobile('POST', 'sync/pull', ['cursor' => $snapshot['cursor'], 'limit' => 1])->assertOk()->assertJsonCount(0, 'changes')->assertJsonPath('has_more', false);
        $this->assertStringNotContainsString('FOREIGN-ACTIVITY-ONLY', $response->getContent());
    }

    public function test_device_cursors_and_operation_results_cannot_be_reused_by_another_user_or_device(): void
    {
        $payload = $this->productPayload();
        $this->mobileLogin();
        $operation = $this->operation('product.create', $payload);
        $this->push([$operation])->assertJsonPath('results.0.status', 'accepted');
        $snapshot = $this->download(['sync_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId]);
        $this->mobileLogin(User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('sales_cashier')->id]));
        $this->mobile('GET', 'sync/operations/'.$operation['operation_uuid'])->assertNotFound()->assertDontSee($payload['name']);
        $this->mobile('POST', 'sync/pull', ['cursor' => $snapshot['cursor']])->assertForbidden();
        $this->push([$operation])->assertJsonPath('results.0.status', 'conflict_requires_review')->assertJsonMissingPath('results.0.result.product_id');
        $role = Role::create(['name' => 'limited-mobile', 'display_name' => 'Limited', 'is_active' => true]);
        $this->mobileLogin(User::factory()->create(['branch_id' => $this->branchId, 'role_id' => $role->id]));
        foreach (['products', 'variants', 'stocks', 'customers', 'sales', 'accounts', 'suppliers'] as $entity) {
            $this->mobile('GET', 'data/'.$entity.'?branch_id='.$this->branchId)->assertForbidden();
        }
    }

    public function test_sync_query_count_is_bounded_as_more_visible_records_are_returned(): void
    {
        $this->stock();
        $this->mobileLogin();
        $measure = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->mobile('POST', 'sync/bootstrap', ['sync_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'limit' => 200])->assertOk();
            $count = collect(DB::getQueryLog())->filter(fn ($query) => str_starts_with(strtolower($query['query']), 'select'))->count();
            DB::disableQueryLog();

            return $count;
        };
        $single = $measure();
        for ($index = 0; $index < 15; $index++) {
            $this->stock('35', '20', 2, 'BATCH'.$index);
        }
        $batch = $measure();
        $this->assertLessThanOrEqual($single + 8, $batch, "Select queries grew from {$single} to {$batch}.");
        file_put_contents(storage_path('app/reports/mobile-sync-query-count.json'), json_encode(['one_product_selects' => $single, 'sixteen_products_selects' => $batch], JSON_THROW_ON_ERROR));
    }
}
