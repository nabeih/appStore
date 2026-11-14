<?php

namespace Tests\Feature;

use App\Actions\Finance\PostJournalAction;
use App\Actions\Inventory\OpeningStock\PostOpeningStockAction;
use App\Actions\Inventory\ProgressiveStockProof;
use App\Actions\Inventory\Setup\CorrectInventoryAction;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Color;
use App\Models\FinancialAccount;
use App\Models\Inventory;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OpeningStockEntry;
use App\Models\Size;
use App\Models\User;
use App\Support\Inventory\Setup\InventoryState;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InventoryOpeningCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Branch $branch;

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inventory.allow_setup_changes' => true]);
        $this->owner = User::factory()->salesOwner()->create();
        $this->actingAs($this->owner);
        $this->branch = Branch::factory()->create();
        $category = Category::create(['name' => 'Correction fixture']);
        $color = Color::create(['name' => 'Correction black', 'code' => 'COR-BLK']);
        $size = Size::create(['name' => 'Correction S', 'code' => 'COR-S']);
        $this->payload = ['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branch->id, 'opening_date' => OperationContext::today(),
            'rows' => [['name' => 'Correctable opening', 'code' => 'COR-0001', 'category_id' => $category->id,
                'color_id' => $color->id, 'size_id' => $size->id, 'qty' => 5, 'cost' => '2.50', 'price' => '10.00']]];
    }

    public static function salesModes(): array
    {
        return ['sales closed' => [false], 'sales open' => [true]];
    }

    private function opening(bool $salesOpen): OpeningStockEntry
    {
        DB::table('sales_controls')->where('id', 1)->update(['activated_at' => $salesOpen ? now() : null]);

        return app(PostOpeningStockAction::class)->execute($this->payload, $this->owner->id);
    }

    private function stock(): Inventory
    {
        return Inventory::with('productVariant.product')->firstOrFail();
    }

    private function data(Inventory $stock, array $overrides = []): array
    {
        $stock->refresh()->load('productVariant.product');
        $variant = $stock->productVariant;
        $product = $variant->product;

        return array_replace(['revision' => InventoryState::revision($stock, $variant, $product), 'name' => $product->name,
            'code' => $product->code, 'category_id' => $product->category_id, 'color_id' => $variant->color_id, 'size_id' => $variant->size_id,
            'quantity' => $stock->quantity, 'average_cost' => $stock->average_cost, 'selling_price' => $variant->selling_price,
            'reason' => 'Isolated documented opening correction'], $overrides);
    }

    private function assertMatched(int $expected): void
    {
        $this->assertSame($expected, (int) Inventory::sum('value_minor'));
        $this->assertSame($expected, app(PostJournalAction::class)->balance(FinancialAccount::where('code', 'inventory')->value('id')));
        $this->assertSame(-$expected, app(PostJournalAction::class)->balance(FinancialAccount::where('code', 'opening_equity')->value('id')));
        $this->assertSame((int) JournalLine::sum('debit_minor'), (int) JournalLine::sum('credit_minor'));
        $this->assertDatabaseCount('suppliers', 0);
        $this->assertDatabaseCount('purchase_invoices', 0);
    }

    private function fingerprint(): array
    {
        $snapshot = [];
        foreach (['products', 'product_variants', 'inventories', 'stock_movements', 'opening_stock_entries', 'opening_stock_items',
            'inventory_setup_changes', 'journal_entries', 'journal_lines', 'financial_accounts', 'sales_controls', 'audit_events'] as $table) {
            $snapshot[$table] = hash('sha256', json_encode(DB::table($table)->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR));
        }

        return $snapshot;
    }

    #[DataProvider('salesModes')]
    public function test_quantity_and_cost_changes_keep_the_ledger_matched_and_old_journal_immutable(bool $salesOpen): void
    {
        $entry = $this->opening($salesOpen);
        $stock = $this->stock();
        $original = JournalEntry::with('lines')->firstOrFail()->toArray();
        foreach ([[4, '3.75', 1500], [4, '1.25', 500], [6, '1.25', 750]] as [$quantity, $cost, $value]) {
            $this->putJson(route('inventory.update', $stock), $this->data($stock, ['quantity' => $quantity, 'average_cost' => $cost]))->assertOk();
            $this->assertMatched($value);
            $this->assertSame($value, (int) DB::table('stock_movements')->value('value_change_minor'));
            $this->assertSame($value, Money::minor($entry->fresh()->total_cost));
            $proof = app(ProgressiveStockProof::class)->build($this->branch->id);
            $this->assertTrue($proof['rows'][$stock->product_variant_id]['ready']);
            $this->assertSame(0, $proof['unexplained_financial_minor']);
        }
        $this->assertSame($original, JournalEntry::with('lines')->findOrFail($original['id'])->toArray());
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('inventory_setup_changes', 3);
        $this->assertDatabaseCount('journal_entries', 4);
        $this->assertSame($salesOpen, DB::table('sales_controls')->value('activated_at') !== null);
        $this->assertNull($this->branch->fresh()->opening_stock_closed_at);
    }

    #[DataProvider('salesModes')]
    public function test_api_updates_and_replays_share_the_same_financial_guards(bool $salesOpen): void
    {
        $this->opening($salesOpen);
        $stock = $this->stock();
        $url = '/api/inventories/'.$this->branch->id.'/'.$stock->product_variant_id;
        $data = $this->data($stock, ['average_cost' => '3.00']);
        $this->putJson($url, $data)->assertOk();
        $this->assertMatched(1500);
        $this->putJson($url, $data)->assertConflict();
        $before = $this->fingerprint();
        $this->putJson($url, $this->data($stock))->assertOk();
        $this->assertSame($before, $this->fingerprint());
        $this->assertDatabaseCount('inventory_setup_changes', 1);
        $this->assertDatabaseCount('journal_entries', 2);
    }

    #[DataProvider('salesModes')]
    public function test_deleting_a_row_then_the_last_row_posts_only_the_value_removed(bool $salesOpen): void
    {
        $second = $this->payload['rows'][0];
        $second['size_id'] = Size::create(['name' => 'Correction M', 'code' => 'COR-M'])->id;
        $second['qty'] = 2;
        $second['cost'] = '3.00';
        $this->payload['rows'][] = $second;
        $entry = $this->opening($salesOpen);
        $stock = $this->stock();
        $this->assertMatched(1850);
        $this->deleteJson(route('inventory.destroy', $stock), $this->data($stock))->assertOk();
        $this->assertMatched(600);
        $remaining = $this->stock();
        $proof = app(ProgressiveStockProof::class)->build($this->branch->id);
        $this->assertTrue($proof['rows'][$remaining->product_variant_id]['ready']);
        $data = $this->data($remaining);
        $this->deleteJson(route('inventory.destroy', $remaining), $data)->assertOk();
        $this->assertMatched(0);
        $this->deleteJson(route('inventory.destroy', $remaining), $data)->assertNotFound();
        $this->assertSame('cancelled', $entry->fresh()->status);
        $this->assertDatabaseCount('opening_stock_items', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_setup_changes', 2);
        $this->assertDatabaseCount('journal_entries', 3);
    }

    #[DataProvider('salesModes')]
    public function test_unposted_historical_document_cannot_be_changed_or_deleted(bool $salesOpen): void
    {
        $this->opening($salesOpen);
        $journal = JournalEntry::firstOrFail();
        $journal->lines()->delete();
        $journal->delete();
        $stock = $this->stock();
        $before = $this->fingerprint();
        $this->putJson(route('inventory.update', $stock), $this->data($stock, ['quantity' => 6]))->assertUnprocessable()->assertJsonValidationErrors('inventory');
        $this->deleteJson(route('inventory.destroy', $stock), $this->data($stock))->assertUnprocessable();
        $this->putJson('/api/inventories/'.$this->branch->id.'/'.$stock->product_variant_id, $this->data($stock, ['quantity' => 6]))->assertUnprocessable();
        $this->assertSame($before, $this->fingerprint());
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public static function failedPostCases(): array
    {
        return [[false, false], [false, true], [true, false], [true, true]];
    }

    #[DataProvider('failedPostCases')]
    public function test_a_journal_line_failure_rolls_back_the_entire_update_or_delete(bool $salesOpen, bool $delete): void
    {
        $this->opening($salesOpen);
        $stock = $this->stock();
        $data = $this->data($stock, ['quantity' => 4, 'average_cost' => '3.75', 'selling_price' => '15.00', 'code' => 'COR-CHANGED']);
        $before = $this->fingerprint();
        JournalLine::created(fn () => throw new RuntimeException('Isolated line failure after insertion'));
        try {
            $this->withoutExceptionHandling();
            if ($delete) {
                $this->deleteJson(route('inventory.destroy', $stock), $data);
            } else {
                $this->putJson(route('inventory.update', $stock), $data);
            }
            $this->fail('Expected journal failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('Isolated line failure after insertion', $error->getMessage());
        } finally {
            JournalLine::flushEventListeners();
        }
        $this->assertSame($before, $this->fingerprint());
        $this->assertMatched(1250);
    }

    public static function brokenEvidenceCases(): array
    {
        return [['amount'], ['account'], ['date'], ['branch'], ['reversal'], ['missing correction'], ['reversed correction'], ['unknown value'], ['stock value']];
    }

    #[DataProvider('brokenEvidenceCases')]
    public function test_unproven_or_reversed_financial_support_is_rejected_without_writes(string $kind): void
    {
        $entry = $this->opening(false);
        $stock = $this->stock();
        $journal = JournalEntry::firstOrFail();
        if (in_array($kind, ['missing correction', 'reversed correction'], true)) {
            $this->putJson(route('inventory.update', $stock), $this->data($stock, ['quantity' => 4]))->assertOk();
            $journal = JournalEntry::where('event', 'opening_correction')->firstOrFail();
        }
        if ($kind === 'missing correction') {
            $journal->lines()->delete();
            $journal->delete();
        } else {
            match ($kind) {
                'amount' => $journal->lines()->where('debit_minor', '>', 0)->increment('debit_minor'),
                'account' => $journal->lines()->where('debit_minor', '>', 0)->update(['financial_account_id' => FinancialAccount::where('code', 'cogs')->value('id')]),
                'date' => $journal->update(['transaction_date' => now()->subDay()->toDateString()]),
                'branch' => $journal->update(['branch_id' => Branch::factory()->create()->id]),
                'reversal', 'reversed correction' => OperationContext::run(fn () => app(PostJournalAction::class)->reverse($journal, $this->owner, 'Isolated reversal', OperationContext::today())),
                'unknown value' => $stock->update(['value_minor' => null]),
                'stock value' => $stock->update(['value_minor' => 9999]),
            };
        }
        $before = $this->fingerprint();
        $this->putJson(route('inventory.update', $stock), $this->data($stock, ['quantity' => 7]))->assertUnprocessable()->assertJsonValidationErrors('inventory');
        $this->deleteJson(route('inventory.destroy', $stock), $this->data($stock))->assertUnprocessable();
        $this->assertSame($before, $this->fingerprint());
        $this->assertSame('approved', $entry->fresh()->status);
    }

    #[DataProvider('salesModes')]
    public function test_explicit_zero_values_and_price_only_changes_do_not_become_unknown_cost(bool $salesOpen): void
    {
        $this->payload['rows'][0]['cost'] = '0.00';
        $this->opening($salesOpen);
        $stock = $this->stock();
        foreach ([['selling_price' => '11.00'], ['average_cost' => '1.00'], ['quantity' => 0, 'average_cost' => '0.00']] as $overrides) {
            $this->putJson(route('inventory.update', $stock), $this->data($stock, $overrides))->assertOk();
            $this->assertMatched(Money::multiply((int) $stock->fresh()->quantity, Money::minor($stock->fresh()->average_cost)));
        }
    }

    public function test_a_correction_cannot_run_without_an_authorized_actor(): void
    {
        $this->opening(false);
        $stock = $this->stock();
        $before = $this->fingerprint();
        try {
            app(CorrectInventoryAction::class)->update($stock, $this->data($stock, ['quantity' => 6]));
            $this->fail('Expected an explicit actor.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame($before, $this->fingerprint());
    }
}
