<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Color;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Size;
use App\Models\User;
use App\Support\ExcelDownload;
use App\Support\Sales\SalesAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use SimpleXMLElement;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;
use ZipArchive;

class ReadinessExcelTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->salesOwner()->create());
        $this->branch = Branch::factory()->create();
    }

    private function stock(int $index, ?int $value, ?Branch $branch = null): Inventory
    {
        $category = Category::firstOrCreate(['name' => 'Excel fixtures']);
        $color = Color::firstOrCreate(['code' => 'BLK'], ['name' => 'Black']);
        $size = Size::firstOrCreate(['code' => 'S'], ['name' => 'S']);
        $product = Product::create(['name' => '=XLSX fixture '.$index, 'code' => '000'.$index, 'category_id' => $category->id]);
        $variant = $product->variants()->create(['sku' => '000'.$index, 'color_id' => $color->id, 'size_id' => $size->id, 'selling_price' => '30']);

        return Inventory::create(['branch_id' => ($branch ?? $this->branch)->id, 'product_variant_id' => $variant->id,
            'quantity' => $index === 0 ? 0 : 3, 'average_cost' => '12.50', 'value_minor' => $value]);
    }

    private function sheets(BinaryFileResponse $response): array
    {
        $path = $response->getFile()->getPathname();
        $archive = new ZipArchive;
        $this->assertTrue($archive->open($path, ZipArchive::CHECKCONS));
        try {
            $book = new SimpleXMLElement($archive->getFromName('xl/workbook.xml'));
            $this->assertNotEmpty($book->xpath('//*[local-name()="sheet"]'));
            $result = [];
            for ($index = 1; ($data = $archive->getFromName('xl/worksheets/sheet'.$index.'.xml')) !== false; $index++) {
                $xml = new SimpleXMLElement($data);
                $this->assertSame([], $xml->xpath('//*[local-name()="f"]'));
                $result[] = $xml;
            }

            return $result;
        } finally {
            $archive->close();
            unlink($path);
        }
    }

    private function cell(SimpleXMLElement $sheet, string $address): SimpleXMLElement
    {
        return $sheet->xpath('//*[local-name()="c"][@r="'.$address.'"]')[0];
    }

    public function test_inventory_excel_has_all_filtered_rows_numeric_values_text_codes_and_no_formulas(): void
    {
        for ($index = 0; $index < 36; $index++) {
            $this->stock($index, $index === 0 ? null : 3750);
        }
        $foreign = Branch::factory()->create();
        $this->stock(99, 99999, $foreign);
        $before = $this->fingerprint();
        $response = $this->get(route('inventory.review.export', ['branch_id' => $this->branch->id, 'q' => 'XLSX', 'page' => 2]))
            ->assertOk()->assertDownload('inventory-readiness.xlsx')->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $sheets = $this->sheets($response->baseResponse);
        $this->assertCount(37, $sheets[0]->xpath('//*[local-name()="row"]'));
        $this->assertSame('inlineStr', (string) $this->cell($sheets[0], 'B2')['t']);
        $this->assertStringContainsString('=XLSX', $this->cell($sheets[0], 'B2')->asXML());
        $this->assertSame('inlineStr', (string) $this->cell($sheets[0], 'E2')['t']);
        $this->assertStringContainsString('0000', $this->cell($sheets[0], 'E2')->asXML());
        $this->assertSame([], $this->cell($sheets[0], 'I2')->xpath('./*[local-name()="v"]'));
        $this->assertSame('37.50', (string) $this->cell($sheets[0], 'I3')->xpath('./*[local-name()="v"]')[0]);
        $this->assertStringNotContainsString('99999', $sheets[0]->asXML());
        $this->assertSame($before, $this->fingerprint());
        $this->getJson(route('inventory.review.export', ['branch_id' => $this->branch->id]))->assertOk()->assertHeader('Content-Type', 'application/json');
    }

    private function fingerprint(): array
    {
        $result = [];
        foreach (['users', 'roles', 'permission_role', 'products', 'inventories', 'stock_movements', 'financial_accounts', 'journal_entries', 'journal_lines', 'sales_controls', 'audit_events'] as $table) {
            $result[$table] = hash('sha256', json_encode(DB::table($table)->get()->all(), JSON_THROW_ON_ERROR));
        }

        return $result;
    }

    public function test_setup_and_transition_downloads_are_real_workbooks_and_do_not_write(): void
    {
        $this->stock(1, null);
        $before = $this->fingerprint();
        foreach ([route('inventory.review.export', ['setup' => 1, 'branch_id' => $this->branch->id]),
            route('finance.transition.index', ['export' => 1, 'branch_id' => $this->branch->id])] as $url) {
            $response = $this->get($url)->assertOk();
            $this->assertGreaterThan(1, count($this->sheets($response->baseResponse)));
            $this->assertSame($before, $this->fingerprint());
        }
        $this->getJson(route('finance.transition.index', ['export' => 1]))->assertOk()->assertJsonPath('read_only', true);
    }

    public function test_branch_scope_and_cost_permission_apply_to_excel_downloads(): void
    {
        $this->stock(1, 3750);
        $other = Branch::factory()->create();
        $this->stock(2, 7500, $other);
        $role = SalesAccess::provisionRole('sales_manager');
        $role->permissions()->syncWithoutDetaching(DB::table('permissions')->whereIn('name', ['inventory.valuation_review', 'sales.view_cost', 'finance.transition_review', 'inventory.close_setup'])->pluck('id'));
        $actor = User::factory()->create(['role_id' => $role->id, 'branch_id' => $this->branch->id]);
        $this->actingAs($actor);
        $response = $this->get(route('inventory.review.export'))->assertOk();
        $sheets = $this->sheets($response->baseResponse);
        $this->assertCount(2, $sheets[0]->xpath('//*[local-name()="row"]'));
        $this->get(route('finance.transition.index', ['export' => 1, 'branch_id' => $other->id]))->assertForbidden();
        $role->permissions()->detach(DB::table('permissions')->where('name', 'sales.view_cost')->value('id'));
        $actor->unsetRelation('role');
        $this->get(route('inventory.review.export'))->assertForbidden();
    }

    public function test_text_that_looks_like_excel_formulas_remains_literal_text(): void
    {
        $response = app(ExcelDownload::class)->download('literal.xlsx', ['نصوص' => [['=1+1', '+SUM(A1)', '-2', '@SUM(A1)', '00014', ['number' => '-2.50'], null]]]);
        $sheet = $this->sheets($response)[0];
        foreach (['A1', 'B1', 'C1', 'D1', 'E1'] as $cell) {
            $this->assertSame('inlineStr', (string) $this->cell($sheet, $cell)['t']);
        }
        $this->assertSame('-2.50', (string) $this->cell($sheet, 'F1')->xpath('./*[local-name()="v"]')[0]);
    }
}
