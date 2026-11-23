<?php

namespace Tests\Feature\Inventory;

use App\Actions\Catalog\CreateSizeAction;
use App\Actions\Finance\PostJournalAction;
use App\Actions\Inventory\ProgressiveStockProof;
use App\Models\Category;
use App\Models\OpeningStockEntry;
use App\Models\Permission;
use App\Models\User;
use App\Support\ExcelDownload;
use App\Support\OpeningWorkbook;
use App\Support\Sales\SalesAccess;
use Database\Factories\BranchFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Sales\SalesTestCase;
use ZipArchive;

class OpeningWorkbookTest extends SalesTestCase
{
    public function test_inactive_catalog_and_foreign_branch_are_rejected_before_saving(): void
    {
        $data = $this->data([$this->row()]);
        $other = BranchFactory::new()->create();
        $this->owner->forceFill(['branch_id' => $this->branchId])->save();
        $this->owner->role->permissions()->detach(Permission::where('name', 'sales.all_branches')->value('id'));
        $this->actingAs($this->owner->fresh())->postJson(route('inventory.opening-stock.import.preview'), array_replace($data, ['branch_id' => $other->id]))->assertForbidden();
        Category::create(['name' => 'ملابس الاختبار'])->forceFill(['is_active' => false])->save();
        $this->postJson(route('inventory.opening-stock.import.preview'), $data)->assertUnprocessable();
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('opening_stock_entries', 0);
    }

    public function test_catalog_failure_rolls_back_all_new_master_data_and_drafts(): void
    {
        $data = $this->data([$this->row()]);
        $token = $this->postJson(route('inventory.opening-stock.import.preview'), $data)->assertOk()->json('token');
        $this->mock(CreateSizeAction::class, function ($mock): void {
            $mock->shouldReceive('execute')->once()->andThrow(ValidationException::withMessages(['size' => 'فشل حفظ مقاس مصطنع']));
        });
        $this->postJson(route('inventory.opening-stock.import.store'), $data + ['token' => $token, 'confirmed' => true])->assertUnprocessable();
        foreach (['categories', 'colors', 'sizes', 'products', 'opening_stock_entries', 'inventories', 'journal_entries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_excel_shared_strings_and_actual_sheet_relationship_are_supported(): void
    {
        $data = $this->data([$this->row()]);
        $zip = new ZipArchive;
        $zip->open($data['file']->getRealPath());
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $sheet = preg_replace('/<c r="A2"[^>]*>.*?<\/c>/s', '<c r="A2" t="s"><v>0</v></c>', $sheet);
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>قميص التجربة</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet2.xml', $sheet);
        $zip->addFromString('xl/_rels/workbook.xml.rels', str_replace('worksheets/sheet1.xml', 'worksheets/sheet2.xml', $zip->getFromName('xl/_rels/workbook.xml.rels')));
        $zip->close();
        $this->postJson(route('inventory.opening-stock.import.preview'), $data)->assertOk()->assertJsonPath('rows.0.product_name', 'قميص التجربة');
        $this->assertDatabaseCount('products', 0);
    }

    protected bool $salesFixtureStarted = false;

    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function row(array $changes = []): array
    {
        return array_replace(['product_name' => 'قميص التجربة', 'category' => 'ملابس الاختبار', 'color' => 'أحمر', 'size' => 'M',
            'opening_quantity' => '2', 'unit_cost' => '10.00', 'selling_price' => '25.00', 'product_code' => '', 'zero_cost_confirmed' => ''], $changes);
    }

    private function data(array $rows): array
    {
        $response = app(ExcelDownload::class)->download('opening.xlsx', ['Opening Stock' => [OpeningWorkbook::HEADERS, ...array_map('array_values', $rows)]]);
        $path = $response->getFile()->getPathname();
        $this->files[] = $path;

        return ['file' => new UploadedFile($path, 'opening.xlsx', null, null, true), 'branch_id' => $this->branchId, 'opening_date' => now()->toDateString(), 'currency' => 'ILS'];
    }

    public function test_six_variants_preview_draft_official_approval_and_replay_are_balanced(): void
    {
        $this->get(route('inventory.opening-stock.import.index'))->assertOk()->assertSee('لا ترفع ملفات SQL');
        $this->get(route('inventory.opening-stock.import.template'))->assertOk()->assertDownload('opening-stock-template.xlsx');
        $rows = [];
        foreach (['أحمر', 'أزرق', 'أخضر'] as $color) {
            foreach (['M', 'L'] as $size) {
                $rows[] = $this->row(['color' => $color, 'size' => $size]);
            }
        }
        $data = $this->data($rows);
        $preview = $this->postJson(route('inventory.opening-stock.import.preview'), $data)->assertOk()->assertJsonPath('row_count', 6)->assertJsonPath('total_cost', '120.00');
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('opening_stock_entries', 0);
        $save = $data + ['token' => $preview->json('token'), 'confirmed' => true];
        $this->postJson(route('inventory.opening-stock.import.store'), $save)->assertCreated()->assertJsonPath('documents.0.status', 'draft');
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 6);
        $this->assertDatabaseCount('inventories', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $entry = OpeningStockEntry::sole();
        $this->postJson(route('inventory.opening-stock.drafts.approve', $entry), ['version' => 1])->assertOk();
        $this->postJson(route('inventory.opening-stock.drafts.approve', $entry), ['version' => 1])->assertOk();
        $this->postJson(route('inventory.opening-stock.import.store'), $save)->assertCreated()->assertJsonPath('documents.0.status', 'approved');
        $this->assertDatabaseCount('opening_stock_entries', 1);
        $this->assertDatabaseCount('stock_movements', 6);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertSame(12000, $this->balance('inventory'));
        $this->assertSame(-12000, $this->balance('opening_equity'));
        $this->assertSame(0, $this->balance('supplier_payables'));
        $this->assertTrue(collect(app(ProgressiveStockProof::class)->build($this->branchId)['rows'])->every(fn ($row) => $row['ready']));
    }

    public function test_large_file_is_split_into_idempotent_300_row_drafts(): void
    {
        $data = $this->data(array_map(fn ($index) => $this->row(['size' => 'S'.$index]), range(1, 301)));
        $token = $this->postJson(route('inventory.opening-stock.import.preview'), $data)->assertOk()->assertJsonPath('documents', 2)->json('token');
        foreach ([1, 2] as $attempt) {
            $this->postJson(route('inventory.opening-stock.import.store'), $data + ['token' => $token, 'confirmed' => true])->assertCreated()->assertJsonCount(2, 'documents');
        }
        $this->assertDatabaseCount('opening_stock_entries', 2);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 301);
        $this->assertDatabaseCount('inventories', 0);
    }

    public function test_invalid_cost_quantity_duplicates_and_explicit_zero(): void
    {
        foreach ([[$this->row(['unit_cost' => ''])], [$this->row(['opening_quantity' => '-1'])], [$this->row(), $this->row()], [$this->row(['unit_cost' => '0'])], [$this->row(['unit_cost' => '9999999.99', 'opening_quantity' => '1000000'])]] as $rows) {
            $this->postJson(route('inventory.opening-stock.import.preview'), $this->data($rows))->assertUnprocessable();
        }
        $this->postJson(route('inventory.opening-stock.import.preview'), $this->data([$this->row(['unit_cost' => '0.00', 'zero_cost_confirmed' => '1'])]))->assertOk();
        $this->assertDatabaseCount('products', 0);
    }

    public function test_confirmation_binding_permissions_and_closed_branch_are_enforced(): void
    {
        $data = $this->data([$this->row()]);
        $token = $this->postJson(route('inventory.opening-stock.import.preview'), $data)->assertOk()->json('token');
        $changed = $this->data([$this->row(['opening_quantity' => '3'])]);
        $this->postJson(route('inventory.opening-stock.import.store'), $changed + ['token' => $token, 'confirmed' => true])->assertConflict();
        $this->postJson(route('inventory.opening-stock.import.store'), $data + ['token' => $token])->assertUnprocessable();
        $cashier = User::factory()->create(['role_id' => SalesAccess::provisionRole('sales_cashier')->id, 'branch_id' => $this->branchId]);
        $this->actingAs($cashier)->postJson(route('inventory.opening-stock.import.preview'), $data)->assertForbidden();
        $this->actingAs($this->owner);
        DB::table('branches')->where('id', $this->branchId)->update(['opening_stock_closed_at' => now()]);
        $this->postJson(route('inventory.opening-stock.import.store'), $data + ['token' => $token, 'confirmed' => true])->assertUnprocessable();
        $this->assertDatabaseCount('products', 0);
    }

    public function test_journal_failure_keeps_draft_without_partial_stock(): void
    {
        $data = $this->data([$this->row()]);
        $token = $this->postJson(route('inventory.opening-stock.import.preview'), $data)->assertOk()->json('token');
        $this->postJson(route('inventory.opening-stock.import.store'), $data + ['token' => $token, 'confirmed' => true])->assertCreated();
        $this->mock(PostJournalAction::class, function ($mock): void {
            $mock->shouldReceive('execute')->once()->andThrow(ValidationException::withMessages(['account' => 'فشل القيد للاختبار']));
        });
        $entry = OpeningStockEntry::sole();
        $this->postJson(route('inventory.opening-stock.drafts.approve', $entry), ['version' => 1])->assertUnprocessable();
        $this->assertSame('draft', $entry->fresh()->status);
        foreach (['inventories', 'stock_movements', 'journal_entries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_formulas_entities_and_sql_are_rejected(): void
    {
        $data = $this->data([$this->row()]);
        $zip = new ZipArchive;
        $zip->open($data['file']->getRealPath());
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->addFromString('xl/worksheets/sheet1.xml', str_replace('<sheetData>', '<sheetData><row r="999"><c r="A999"><f>1+1</f><v>2</v></c></row>', $sheet));
        $zip->close();
        $this->postJson(route('inventory.opening-stock.import.preview'), $data)->assertUnprocessable();
        $data = $this->data([$this->row()]);
        $zip->open($data['file']->getRealPath());
        $zip->addFromString('xl/sharedStrings.xml', '<!DOCTYPE foo [<!ENTITY x SYSTEM "file:///etc/passwd">]><sst>&x;</sst>');
        $zip->close();
        $this->postJson(route('inventory.opening-stock.import.preview'), $data)->assertUnprocessable();
        $data['file'] = UploadedFile::fake()->createWithContent('legacy.sql', 'DELETE FROM products;');
        $this->postJson(route('inventory.opening-stock.import.preview'), $data)->assertUnprocessable();
        $this->assertDatabaseCount('products', 0);
    }
}
