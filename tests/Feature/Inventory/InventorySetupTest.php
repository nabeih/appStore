<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\OpeningStock\PostOpeningStockAction;
use App\Models\Category;
use App\Models\Color;
use App\Models\Inventory;
use App\Models\InventorySetupChange;
use App\Models\OpeningStockEntry;
use App\Models\OpeningStockItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Inventory\Setup\InventoryState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class InventorySetupTest extends TestCase
{
    use RefreshDatabase;

    private int $branchId;

    private Category $category;

    private Color $color;

    private Size $size;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->salesOwner()->create());
        config(['inventory.allow_setup_changes' => true]);
        $this->branchId = DB::table('branches')->insertGetId(['name' => 'الفرع الأول']);
        $this->category = Category::create(['name' => 'ملابس']);
        $this->color = Color::create(['name' => 'أسود', 'code' => 'BLK']);
        $this->size = Size::create(['name' => 'L', 'code' => 'L']);
    }

    private function undocumentedInventory(): Inventory
    {
        $product = Product::create(['name' => 'قميص', 'code' => 'SHIRT', 'category_id' => $this->category->id]);
        $variant = $product->variants()->create(['color_id' => $this->color->id, 'size_id' => $this->size->id, 'sku' => 'SHIRT-BLK-L', 'selling_price' => '10.00']);

        return Inventory::create(['branch_id' => $this->branchId, 'product_variant_id' => $variant->id, 'quantity' => 5, 'average_cost' => '2.50']);
    }

    private function setupInventory(): Inventory
    {
        app(PostOpeningStockAction::class)->execute($this->openingPayload());

        return Inventory::firstOrFail();
    }

    private function openingPayload(bool $twoRows = false): array
    {
        $row = ['name' => 'قميص', 'code' => 'SHIRT', 'category_id' => $this->category->id, 'color_id' => $this->color->id,
            'size_id' => $this->size->id, 'qty' => 5, 'cost' => '2.50', 'price' => '10.00'];
        $rows = [$row];
        if ($twoRows) {
            $size = Size::create(['name' => 'XL', 'code' => 'XL']);
            $rows[] = array_replace($row, ['size_id' => $size->id, 'qty' => 2, 'cost' => '3.00']);
        }

        return ['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'opening_date' => now()->toDateString(), 'rows' => $rows];
    }

    private function revision(Inventory $inventory): string
    {
        $inventory->refresh()->load('productVariant.product');

        return InventoryState::revision($inventory, $inventory->productVariant, $inventory->productVariant->product);
    }

    private function updateData(Inventory $inventory, array $overrides = []): array
    {
        return array_replace(['revision' => $this->revision($inventory), 'name' => $inventory->productVariant->product->name, 'code' => $inventory->productVariant->product->code, 'category_id' => $inventory->productVariant->product->category_id, 'color_id' => $inventory->productVariant->color_id, 'size_id' => $inventory->productVariant->size_id, 'quantity' => 4, 'average_cost' => '0.10', 'selling_price' => '11.25', 'reason' => 'تصحيح الإدخال'], $overrides);
    }

    public function test_undocumented_legacy_stock_cannot_be_corrected_as_independent_numbers(): void
    {
        $inventory = $this->undocumentedInventory();
        $this->putJson(route('inventory.update', $inventory), $this->updateData($inventory))->assertUnprocessable()->assertJsonValidationErrors('inventory');
        $this->assertDatabaseHas('inventories', ['id' => $inventory->id, 'quantity' => 5, 'average_cost' => '2.50']);
        $this->assertDatabaseHas('product_variants', ['id' => $inventory->product_variant_id, 'selling_price' => '10.00']);
        $this->assertDatabaseCount('inventory_setup_changes', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_update_synchronizes_the_opening_item_movement_and_document_totals(): void
    {
        $entry = app(PostOpeningStockAction::class)->execute($this->openingPayload(true));
        $inventory = Inventory::orderBy('id')->firstOrFail();
        $this->putJson(route('inventory.update', $inventory), $this->updateData($inventory))->assertOk();
        $entry->refresh();
        $this->assertSame('6.40', $entry->total_cost);
        $this->assertSame(6, $entry->total_quantity);
        $item = OpeningStockItem::where('product_variant_id', $inventory->product_variant_id)->firstOrFail();
        $this->assertSame('0.40', $item->line_total);
        $this->assertSame('11.25', $item->selling_price);
        $this->assertDatabaseHas('stock_movements', ['source_id' => $item->id, 'quantity_change' => 4, 'unit_cost' => '0.10']);
        $this->assertDatabaseCount('inventory_setup_changes', 1);
        $this->get(route('inventory.opening-stock.show', $entry))->assertOk()->assertSee('تصحيحات إدخال البضاعة')->assertSee('تصحيح الإدخال');
    }

    public function test_undocumented_legacy_stock_cannot_be_deleted_without_source_review(): void
    {
        $inventory = $this->undocumentedInventory();
        $other = DB::table('branches')->insertGetId(['name' => 'فرع آخر']);
        Inventory::create(['branch_id' => $other, 'product_variant_id' => $inventory->product_variant_id, 'quantity' => 20, 'average_cost' => '2.00']);
        $this->deleteJson(route('inventory.destroy', $inventory), ['revision' => $this->revision($inventory)])->assertUnprocessable();
        $this->assertDatabaseHas('inventories', ['id' => $inventory->id, 'quantity' => 5]);
        $this->assertDatabaseHas('inventories', ['branch_id' => $other, 'quantity' => 20]);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 1);
        $this->assertDatabaseCount('inventory_setup_changes', 0);
    }

    public function test_deleting_one_opening_row_recalculates_totals_and_keeps_the_document(): void
    {
        $entry = app(PostOpeningStockAction::class)->execute($this->openingPayload(true));
        $inventory = Inventory::orderBy('id')->firstOrFail();
        $this->deleteJson(route('inventory.destroy', $inventory), ['revision' => $this->revision($inventory)])->assertOk();
        $this->assertDatabaseCount('opening_stock_items', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('inventories', 1);
        $entry->refresh();
        $this->assertSame('6.00', $entry->total_cost);
        $this->assertSame(2, $entry->total_quantity);
        $this->assertSame('approved', $entry->status);
    }

    public function test_deleting_the_last_row_cancels_the_document_and_replaying_does_not_restore_stock(): void
    {
        $payload = $this->openingPayload();
        $entry = app(PostOpeningStockAction::class)->execute($payload);
        $inventory = Inventory::firstOrFail();
        $this->deleteJson(route('inventory.destroy', $inventory), ['revision' => $this->revision($inventory)])->assertOk();
        $entry->refresh();
        $this->assertSame('cancelled', $entry->status);
        $this->assertSame('0.00', $entry->total_cost);
        $this->assertSame(0, $entry->total_quantity);
        $this->assertDatabaseCount('opening_stock_entries', 1);
        $this->postJson(route('inventory.opening-stock.store'), $payload)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('inventories', 0);
        $this->get(route('inventory.opening-stock.show', $entry))->assertOk()->assertSee('ملغى أثناء التجهيز')->assertSee('حذف سطر');
        $this->get(route('inventory.opening-stock.index'))->assertOk()->assertSee('ملغى أثناء التجهيز');
    }

    public function test_a_corrected_document_replay_does_not_reset_the_correction(): void
    {
        $payload = $this->openingPayload();
        app(PostOpeningStockAction::class)->execute($payload);
        $inventory = Inventory::firstOrFail();
        $this->putJson(route('inventory.update', $inventory), $this->updateData($inventory))->assertOk();
        $this->postJson(route('inventory.opening-stock.store'), $payload)->assertOk()->assertJsonPath('total_cost', '0.40');
        $this->assertDatabaseHas('inventories', ['quantity' => 4]);
    }

    public function test_stock_with_subsequent_movements_cannot_be_edited_or_deleted(): void
    {
        app(PostOpeningStockAction::class)->execute($this->openingPayload());
        $inventory = Inventory::firstOrFail();
        StockMovement::create(['branch_id' => $this->branchId, 'product_variant_id' => $inventory->product_variant_id,
            'movement_type' => 'sale', 'quantity_change' => -1, 'source_type' => 'sale', 'source_id' => 123]);
        $this->putJson(route('inventory.update', $inventory), $this->updateData($inventory))->assertUnprocessable()->assertJsonValidationErrors('inventory');
        $this->deleteJson(route('inventory.destroy', $inventory), ['revision' => $this->revision($inventory)])->assertUnprocessable();
        $this->assertDatabaseCount('inventory_setup_changes', 0);
        $this->assertDatabaseHas('inventories', ['quantity' => 5]);
    }

    public function test_legacy_stock_with_a_non_opening_movement_is_also_protected(): void
    {
        $inventory = $this->undocumentedInventory();
        StockMovement::create(['branch_id' => $this->branchId, 'product_variant_id' => $inventory->product_variant_id,
            'movement_type' => 'purchase', 'quantity_change' => 5, 'source_type' => 'purchase', 'source_id' => 1]);
        $this->deleteJson(route('inventory.destroy', $inventory), ['revision' => $this->revision($inventory)])->assertUnprocessable();
        $this->assertDatabaseCount('inventories', 1);
    }

    public function test_stale_requests_cannot_overwrite_or_delete_newer_data(): void
    {
        $inventory = $this->setupInventory();
        $data = $this->updateData($inventory);
        $this->putJson(route('inventory.update', $inventory), $data)->assertOk();
        $data['quantity'] = 99;
        $this->putJson(route('inventory.update', $inventory), $data)->assertConflict();
        $this->deleteJson(route('inventory.destroy', $inventory), ['revision' => $data['revision']])->assertConflict();
        $this->assertDatabaseHas('inventories', ['quantity' => 4]);
        $this->assertDatabaseCount('inventory_setup_changes', 1);
    }

    public function test_changing_the_global_price_invalidates_an_already_opened_row(): void
    {
        $inventory = $this->setupInventory();
        $data = $this->updateData($inventory);
        $inventory->productVariant->update(['selling_price' => '19.00']);
        $this->putJson(route('inventory.update', $inventory), $data)->assertConflict();
    }

    public function test_disabling_setup_changes_hides_the_actions_and_blocks_web_and_api_writes(): void
    {
        $inventory = $this->setupInventory();
        $data = $this->updateData($inventory);
        config(['inventory.allow_setup_changes' => false]);
        $this->get(route('inventory.index'))->assertOk()->assertDontSee('data-inventory-action', false)->assertDontSee('inventory-setup-config');
        $this->putJson(route('inventory.update', $inventory), $data)->assertForbidden();
        $this->deleteJson(route('inventory.destroy', $inventory), ['revision' => $data['revision']])->assertForbidden();
        $this->putJson('/api/inventories/'.$this->branchId.'/'.$inventory->product_variant_id, $data)->assertForbidden();
        $this->assertDatabaseHas('inventories', ['quantity' => 5]);
    }

    public function test_invalid_quantities_prices_and_precision_are_rejected(): void
    {
        $inventory = $this->setupInventory();
        foreach ([['quantity' => -1], ['quantity' => '1.5'], ['quantity' => 1000001], ['average_cost' => '0.001'], ['selling_price' => '-5']] as $invalid) {
            $this->putJson(route('inventory.update', $inventory), $this->updateData($inventory, $invalid))->assertUnprocessable();
        }
        $this->assertDatabaseCount('inventory_setup_changes', 0);
    }

    public function test_zero_quantity_and_zero_cost_are_valid_setup_corrections(): void
    {
        app(PostOpeningStockAction::class)->execute($this->openingPayload());
        $inventory = Inventory::firstOrFail();
        $this->putJson(route('inventory.update', $inventory), $this->updateData($inventory, ['quantity' => 0, 'average_cost' => '0', 'selling_price' => '0']))->assertOk();
        $this->assertDatabaseHas('opening_stock_entries', ['total_quantity' => 0, 'status' => 'approved']);
        $this->assertSame('0.00', OpeningStockEntry::firstOrFail()->total_cost);
    }

    public function test_an_audit_write_failure_rolls_back_stock_price_and_document_changes(): void
    {
        app(PostOpeningStockAction::class)->execute($this->openingPayload());
        $inventory = Inventory::firstOrFail();
        InventorySetupChange::creating(function (): void {
            throw new RuntimeException('Audit failure');
        });
        $this->withoutExceptionHandling();
        try {
            $this->putJson(route('inventory.update', $inventory), $this->updateData($inventory));
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit failure', $exception->getMessage());
        } finally {
            InventorySetupChange::flushEventListeners();
        }
        $this->assertDatabaseHas('inventories', ['quantity' => 5, 'average_cost' => '2.50']);
        $this->assertDatabaseHas('product_variants', ['selling_price' => '10.00']);
        $this->assertDatabaseHas('stock_movements', ['quantity_change' => 5]);
        $this->assertSame('12.50', OpeningStockEntry::firstOrFail()->total_cost);
    }

    public function test_existing_api_updates_follow_the_same_document_and_audit_rules(): void
    {
        app(PostOpeningStockAction::class)->execute($this->openingPayload());
        $inventory = Inventory::firstOrFail();
        $response = $this->getJson('/api/inventories')->assertOk();
        $this->putJson('/api/inventories/'.$this->branchId.'/'.$inventory->product_variant_id, [
            'revision' => $response->json('data.0.setup_revision'), 'quantity' => 2, 'average_cost' => '1.25',
        ])->assertOk();
        $this->assertSame('2.50', OpeningStockEntry::firstOrFail()->total_cost);
        $this->assertDatabaseHas('product_variants', ['selling_price' => '10.00']);
        $this->assertDatabaseCount('inventory_setup_changes', 1);
    }

    public function test_stock_rows_include_action_buttons_and_revision(): void
    {
        $this->setupInventory();
        $this->get(route('inventory.index'))->assertOk()->assertSee('data-inventory-action="edit"', false)->assertSee('data-inventory-action="delete"', false)->assertSee('inventorySetupDialog');
    }

    public function test_product_fields_and_all_variant_skus_are_corrected_with_document_snapshots(): void
    {
        app(PostOpeningStockAction::class)->execute($this->openingPayload(true));
        $inventory = Inventory::orderBy('id')->firstOrFail();
        $category = Category::create(['name' => 'أطفال']);
        $color = Color::create(['name' => 'أحمر', 'code' => 'RED']);
        $size = Size::create(['name' => 'M', 'code' => 'M']);
        $this->putJson(route('inventory.update', $inventory), $this->updateData($inventory, [
            'name' => 'قميص جديد', 'code' => 'new-code', 'category_id' => $category->id, 'color_id' => $color->id, 'size_id' => $size->id,
        ]))->assertOk();
        $this->assertDatabaseHas('products', ['name' => 'قميص جديد', 'code' => 'NEW-CODE', 'category_id' => $category->id]);
        $this->assertDatabaseHas('product_variants', ['id' => $inventory->product_variant_id, 'sku' => 'NEW-CODE-RED-M', 'color_id' => $color->id, 'size_id' => $size->id]);
        $this->assertDatabaseHas('product_variants', ['sku' => 'NEW-CODE-BLK-XL']);
        $this->assertDatabaseHas('opening_stock_items', ['product_variant_id' => $inventory->product_variant_id, 'product_name' => 'قميص جديد', 'product_code' => 'NEW-CODE', 'category_name' => 'أطفال', 'color_name' => 'أحمر', 'size_name' => 'M', 'sku' => 'NEW-CODE-RED-M']);
        $change = InventorySetupChange::firstOrFail();
        $this->assertSame(auth()->id(), $change->user_id);
        $this->assertSame('SHIRT', $change->before_state['product_code']);
        $this->assertSame('NEW-CODE', $change->after_state['product_code']);
    }

    public function test_color_or_size_cannot_change_when_the_same_variant_is_stocked_elsewhere(): void
    {
        $inventory = $this->setupInventory();
        $other = DB::table('branches')->insertGetId(['name' => 'فرع آخر']);
        Inventory::create(['branch_id' => $other, 'product_variant_id' => $inventory->product_variant_id, 'quantity' => 5, 'average_cost' => '2.00']);
        $color = Color::create(['name' => 'أحمر', 'code' => 'RED']);
        $this->putJson(route('inventory.update', $inventory), $this->updateData($inventory, ['color_id' => $color->id]))->assertUnprocessable()->assertJsonValidationErrors('inventory');
        $this->assertDatabaseHas('product_variants', ['id' => $inventory->product_variant_id, 'color_id' => $this->color->id]);
        $this->assertDatabaseCount('inventory_setup_changes', 0);
    }

    public function test_duplicate_product_codes_and_variant_combinations_are_rejected(): void
    {
        app(PostOpeningStockAction::class)->execute($this->openingPayload(true));
        $inventory = Inventory::orderBy('id')->firstOrFail();
        Product::create(['name' => 'آخر', 'code' => 'TAKEN', 'category_id' => $this->category->id]);
        $this->putJson(route('inventory.update', $inventory), $this->updateData($inventory, ['code' => 'TAKEN']))->assertUnprocessable()->assertJsonValidationErrors('code');
        $otherVariant = ProductVariant::where('id', '!=', $inventory->product_variant_id)->firstOrFail();
        $this->putJson(route('inventory.update', $inventory), $this->updateData($inventory, ['size_id' => $otherVariant->size_id]))->assertUnprocessable()->assertJsonValidationErrors('inventory');
        $this->assertDatabaseCount('inventory_setup_changes', 0);
    }

    public function test_shared_product_changes_are_blocked_after_any_variant_has_later_movements(): void
    {
        app(PostOpeningStockAction::class)->execute($this->openingPayload(true));
        $inventory = Inventory::orderBy('id')->firstOrFail();
        $otherVariant = ProductVariant::where('id', '!=', $inventory->product_variant_id)->firstOrFail();
        StockMovement::create(['branch_id' => $this->branchId, 'product_variant_id' => $otherVariant->id,
            'movement_type' => 'sale', 'quantity_change' => -1, 'source_type' => 'sale', 'source_id' => 200]);
        $this->putJson(route('inventory.update', $inventory), $this->updateData($inventory, ['name' => 'اسم جديد']))->assertUnprocessable();
        $this->assertDatabaseHas('products', ['name' => 'قميص']);
    }

    public function test_global_price_changes_are_blocked_when_another_branch_has_later_movements(): void
    {
        $inventory = $this->setupInventory();
        $other = DB::table('branches')->insertGetId(['name' => 'فرع آخر']);
        StockMovement::create(['branch_id' => $other, 'product_variant_id' => $inventory->product_variant_id,
            'movement_type' => 'sale', 'quantity_change' => -1, 'source_type' => 'sale', 'source_id' => 200]);
        $this->putJson(route('inventory.update', $inventory), $this->updateData($inventory))->assertUnprocessable();
    }

    public function test_a_product_change_invalidates_an_already_opened_inventory_dialog(): void
    {
        $inventory = $this->setupInventory();
        $data = $this->updateData($inventory);
        $inventory->productVariant->product->update(['name' => 'تغير من شاشة أخرى']);
        $this->putJson(route('inventory.update', $inventory), $data)->assertConflict();
    }

    public function test_minimum_price_and_unknown_attributes_are_validated_before_writes(): void
    {
        $inventory = $this->setupInventory();
        $inventory->productVariant()->update(['minimum_selling_price' => '6.00']);
        $this->putJson(route('inventory.update', $inventory), $this->updateData($inventory, ['selling_price' => '5.99']))->assertUnprocessable()->assertJsonValidationErrors('selling_price');
        $this->putJson(route('inventory.update', $inventory), $this->updateData($inventory, ['color_id' => 99999]))->assertUnprocessable();
        $this->assertDatabaseCount('inventory_setup_changes', 0);
    }

    public function test_an_unchanged_submission_does_not_create_a_false_correction(): void
    {
        $inventory = $this->setupInventory();
        $this->putJson(route('inventory.update', $inventory), $this->updateData($inventory, ['quantity' => 5, 'average_cost' => '2.50', 'selling_price' => '10.00']))->assertOk();
        $this->assertDatabaseCount('inventory_setup_changes', 0);
    }

    public function test_a_failure_after_delete_restores_stock_document_items_and_movements(): void
    {
        app(PostOpeningStockAction::class)->execute($this->openingPayload());
        $inventory = Inventory::firstOrFail();
        InventorySetupChange::creating(function (): void {
            throw new RuntimeException('Delete audit failure');
        });
        $this->withoutExceptionHandling();
        try {
            $this->deleteJson(route('inventory.destroy', $inventory), ['revision' => $this->revision($inventory)]);
            $this->fail('Expected delete audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Delete audit failure', $exception->getMessage());
        } finally {
            InventorySetupChange::flushEventListeners();
        }
        $this->assertDatabaseCount('inventories', 1);
        $this->assertDatabaseCount('opening_stock_items', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('opening_stock_entries', ['status' => 'approved']);
    }

    public function test_inventory_actions_do_not_add_per_row_queries(): void
    {
        $inventory = $this->setupInventory();
        $selectCount = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('inventory.index'))->assertOk();
            $count = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select'))->count();
            DB::disableQueryLog();

            return $count;
        };
        $single = $selectCount();
        for ($index = 0; $index < 19; $index++) {
            $branch = DB::table('branches')->insertGetId(['name' => 'فرع '.$index]);
            Inventory::create(['branch_id' => $branch, 'product_variant_id' => $inventory->product_variant_id, 'quantity' => 5, 'average_cost' => '2.00']);
        }
        $this->assertLessThanOrEqual($single + 1, $selectCount());
    }
}
