<?php

namespace Tests\Feature;

use App\Actions\Finance\PostJournalAction;
use App\Actions\Inventory\OpeningStock\ManageOpeningDraftAction;
use App\Actions\Inventory\OpeningStock\PostOpeningStockAction;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Color;
use App\Models\FinancialAccount;
use App\Models\Inventory;
use App\Models\JournalEntry;
use App\Models\OpeningStockEntry;
use App\Models\ProductImage;
use App\Models\Size;
use App\Models\User;
use App\Support\Inventory\Setup\InventoryState;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class OwnerOpeningExperienceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $branch;

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->owner = User::factory()->salesOwner()->create();
        $this->actingAs($this->owner);
        $this->branch = Branch::factory()->create();
        $category = Category::create(['name' => 'Isolated clothes']);
        $color = Color::create(['name' => 'Black fixture', 'code' => 'BLK']);
        $size = Size::create(['name' => 'S', 'code' => 'S']);
        $this->payload = ['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branch->id,
            'opening_date' => OperationContext::today(), 'rows' => [['name' => 'Opening fixture', 'code' => '00014',
                'category_id' => $category->id, 'color_id' => $color->id, 'size_id' => $size->id, 'qty' => 3, 'cost' => '12.50', 'price' => '30']]];
    }

    public function test_draft_keeps_products_cost_and_photos_without_accounts_or_stock(): void
    {
        $this->payload['rows'][0]['image'] = UploadedFile::fake()->image('new.jpg');
        $this->post(route('inventory.opening-stock.drafts.store'), $this->payload, ['Accept' => 'application/json'])->assertSuccessful();
        $this->assertDatabaseHas('opening_stock_entries', ['status' => 'draft', 'total_cost' => '37.50']);
        $this->assertDatabaseCount('inventories', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('financial_accounts', 0);
        $this->assertCount(2, Storage::disk('public')->allFiles());
    }

    public function test_direct_approval_posts_inventory_against_opening_equity_while_sales_stays_closed(): void
    {
        $this->postJson(route('inventory.opening-stock.store'), $this->payload)->assertCreated()->assertJsonPath('status', 'approved');
        $entry = OpeningStockEntry::firstOrFail();
        $journal = JournalEntry::where('source_type', OpeningStockEntry::class)->where('source_id', $entry->id)->where('event', 'opening')->firstOrFail();
        $this->assertSame(3750, (int) $journal->lines()->sum('debit_minor'));
        $this->assertSame(3750, (int) $journal->lines()->sum('credit_minor'));
        $this->assertSameCanonicalizingCodes($journal);
        $this->assertNull(DB::table('sales_controls')->value('activated_at'));
        $this->assertNull($this->branch->fresh()->opening_stock_closed_at);
        $this->assertDatabaseCount('suppliers', 0);
        $this->assertDatabaseCount('purchase_invoices', 0);
        $this->postJson(route('inventory.opening-stock.store'), $this->payload)->assertOk();
        $this->assertDatabaseCount('inventories', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    private function assertSameCanonicalizingCodes(JournalEntry $entry): void
    {
        $this->assertEqualsCanonicalizing(['inventory', 'opening_equity'], $entry->lines()->with('account')->get()->pluck('account.code')->all());
    }

    public function test_financial_failure_keeps_the_direct_request_as_a_retryable_draft_with_its_photos(): void
    {
        $this->payload['rows'][0]['image'] = UploadedFile::fake()->image('new-preserved.jpg');
        JournalEntry::creating(fn () => throw new RuntimeException('Isolated journal failure'));
        try {
            $this->post(route('inventory.opening-stock.store'), $this->payload, ['Accept' => 'application/json'])
                ->assertCreated()->assertJsonPath('status', 'draft');
        } finally {
            JournalEntry::flushEventListeners();
        }
        $entry = OpeningStockEntry::firstOrFail();
        $this->assertSame('draft', $entry->status);
        $this->assertDatabaseCount('inventories', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertCount(2, Storage::disk('public')->allFiles());
        $paths = ProductImage::firstOrFail()->only('image_path', 'thumbnail_path');
        app(ManageOpeningDraftAction::class)->approve($entry, $entry->version, $this->owner);
        $this->assertDatabaseCount('inventories', 1);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertSame($paths, ProductImage::firstOrFail()->only('image_path', 'thumbnail_path'));
    }

    public function test_old_approved_document_without_a_journal_is_not_reposted_on_retry(): void
    {
        $entry = app(PostOpeningStockAction::class)->execute($this->payload, $this->owner->id, true);
        $entry->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $this->owner->id]);
        $this->postJson(route('inventory.opening-stock.store'), $this->payload)->assertOk();
        $this->assertDatabaseCount('inventories', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->getJson(route('finance.transition.index', ['export' => 1]))->assertOk()->assertJsonPath('documents.0.posted', false);
    }

    public function test_retry_of_the_original_request_cannot_approve_an_edited_draft(): void
    {
        $entry = app(PostOpeningStockAction::class)->execute($this->payload, $this->owner->id, true);
        $manage = app(ManageOpeningDraftAction::class);
        $manage->update($entry, ['version' => 1, 'rows' => [['id' => $entry->items()->first()->id, 'quantity' => 5, 'cost' => '12.50']]], $this->owner);
        $this->postJson(route('inventory.opening-stock.store'), $this->payload)->assertConflict();
        $this->assertDatabaseCount('inventories', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $manage->approve($entry->fresh(), 2, $this->owner);
        $this->assertDatabaseHas('inventories', ['quantity' => 5, 'value_minor' => 6250]);
    }

    public function test_operation_link_and_access_require_the_same_owner_and_permissions(): void
    {
        $this->get(route('inventory.operation.index'))->assertOk();
        $this->get(route('inventory.review.index'))->assertOk()->assertSee('حالة تشغيل المحل وفحص الجاهزية');
        $role = SalesAccess::provisionRole('sales_manager');
        $role->permissions()->syncWithoutDetaching(DB::table('permissions')->whereIn('name', ['inventory.close_setup', 'sales.view_cost', 'inventory.valuation_review'])->pluck('id'));
        $manager = User::factory()->create(['role_id' => $role->id, 'branch_id' => $this->branch->id]);
        $this->actingAs($manager)->get(route('inventory.operation.index'))->assertForbidden();
        $this->get(route('inventory.review.index'))->assertOk()->assertDontSee('حالة تشغيل المحل وفحص الجاهزية')->assertDontSee('حالة تشغيل المحل</a>', false);
        $this->actingAs($this->owner);
        $this->owner->role->permissions()->detach(DB::table('permissions')->where('name', 'inventory.close_setup')->value('id'));
        $this->owner->unsetRelation('role');
        $this->get(route('inventory.operation.index'))->assertForbidden();
        $this->get(route('inventory.review.index'))->assertOk()->assertDontSee('حالة تشغيل المحل وفحص الجاهزية');
    }

    public function test_blocked_account_preserves_draft_and_unknown_cost_is_never_zero(): void
    {
        FinancialAccount::create(['code' => 'inventory', 'name' => 'Inactive inventory fixture', 'type' => 'system', 'is_active' => false]);
        $this->postJson(route('inventory.opening-stock.store'), $this->payload)->assertCreated()->assertJsonPath('status', 'draft');
        $this->get(route('inventory.opening-stock.show', OpeningStockEntry::firstOrFail()))->assertOk()->assertSee('تفاصيل للمسؤول');
        $this->assertDatabaseCount('inventories', 0);
        $this->payload['client_uuid'] = (string) Str::uuid();
        $this->payload['rows'][0]['cost'] = null;
        $this->postJson(route('inventory.opening-stock.store'), $this->payload)->assertUnprocessable();
        $this->assertDatabaseCount('opening_stock_entries', 1);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_draft_with_inconsistent_totals_or_existing_financial_effect_cannot_add_stock(): void
    {
        $entry = app(PostOpeningStockAction::class)->execute($this->payload, $this->owner->id, true);
        $entry->items()->update(['line_total' => '99.00']);
        $this->postJson(route('inventory.opening-stock.drafts.approve', $entry), ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('rows');
        $this->assertSame('draft', $entry->fresh()->status);
        $this->assertDatabaseCount('inventories', 0);
        $entry->items()->update(['line_total' => '37.50']);
        JournalEntry::create(['source_type' => OpeningStockEntry::class, 'source_id' => $entry->id, 'event' => 'opening',
            'branch_id' => $this->branch->id, 'created_by' => $this->owner->id, 'transaction_date' => OperationContext::today(), 'description' => 'Isolated pre-existing effect']);
        $this->postJson(route('inventory.opening-stock.drafts.approve', $entry), ['version' => 1])->assertConflict();
        $this->assertDatabaseCount('inventories', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_opening_correction_keeps_inventory_and_ledger_matched_before_sales_activation(): void
    {
        config(['inventory.allow_setup_changes' => true]);
        $this->postJson(route('inventory.opening-stock.store'), $this->payload)->assertCreated()->assertJsonPath('status', 'approved');
        $stock = Inventory::with('productVariant.product')->firstOrFail();
        $variant = $stock->productVariant;
        $product = $variant->product;
        $this->assertNull(DB::table('sales_controls')->value('activated_at'));
        $this->putJson(route('inventory.update', $stock), [
            'revision' => InventoryState::revision($stock, $variant, $product),
            'name' => $product->name, 'code' => $product->code, 'category_id' => $product->category_id,
            'color_id' => $variant->color_id, 'size_id' => $variant->size_id, 'quantity' => 4,
            'average_cost' => '12.50', 'selling_price' => '30.00', 'reason' => 'Isolated release review correction',
        ])->assertOk();
        $this->assertSame(5000, (int) $stock->fresh()->value_minor);
        $balance = app(PostJournalAction::class)->balance(FinancialAccount::where('code', 'inventory')->value('id'));
        $this->assertSame(5000, $balance, 'Correcting an approved opening while sales are closed must preserve inventory/ledger reconciliation.');
    }
}
