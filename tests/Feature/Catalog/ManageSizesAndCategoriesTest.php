<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\OpeningStockItem;
use App\Models\Product;
use App\Models\Size;
use App\Models\User;
use Tests\Feature\Sales\SalesTestCase;

class ManageSizesAndCategoriesTest extends SalesTestCase
{
    public function test_renaming_a_used_size_preserves_skus_stock_and_document_snapshots(): void
    {
        $variant = $this->stock();
        $invoice = $this->sale($variant);
        $this->putJson(route('catalog.attributes.sizes.update', $variant->size_id), ['name' => '  كبير  ', 'sort_order' => 8])->assertOk();
        $this->assertDatabaseHas('sizes', ['id' => $variant->size_id, 'name' => 'كبير', 'code' => 'L', 'sort_order' => 8]);
        $this->assertSame('SHIRT-BLK-L', $variant->fresh()->sku);
        $this->assertSame('L', OpeningStockItem::firstOrFail()->size_name);
        $this->assertSame('L', $invoice->items()->firstOrFail()->size_name);
        $this->assertDatabaseHas('inventories', ['product_variant_id' => $variant->id, 'quantity' => 8, 'value_minor' => 16000]);
    }

    public function test_size_update_rejects_duplicates_blank_names_invalid_sort_and_code_changes(): void
    {
        $size = Size::create(['name' => 'M', 'code' => 'M']);
        Size::create(['name' => 'L', 'code' => 'L']);
        foreach ([['name' => 'L'], ['name' => '  '], ['name' => str_repeat('س', 101)],
            ['name' => 'M', 'sort_order' => -1], ['name' => 'M', 'sort_order' => 65536],
            ['name' => 'M', 'sort_order' => '1.5'], ['name' => 'M', 'code' => 'NEW']] as $data) {
            $this->putJson(route('catalog.attributes.sizes.update', $size), $data)->assertUnprocessable();
        }
        $this->putJson(route('catalog.attributes.sizes.update', $size), ['name' => 'M'])->assertOk();
        $this->assertDatabaseHas('sizes', ['id' => $size->id, 'name' => 'M', 'code' => 'M']);
    }

    public function test_only_unused_sizes_can_be_deleted_even_after_a_product_is_archived(): void
    {
        $unused = Size::create(['name' => 'XXL', 'code' => 'XXL']);
        $this->deleteJson(route('catalog.attributes.sizes.destroy', $unused))->assertOk();
        $this->assertDatabaseMissing('sizes', ['id' => $unused->id]);
        $variant = $this->stock();
        $variant->product->delete();
        $this->deleteJson(route('catalog.attributes.sizes.destroy', $variant->size_id))->assertUnprocessable();
        $this->assertDatabaseHas('sizes', ['id' => $variant->size_id]);
        $this->assertDatabaseCount('inventories', 1);
    }

    public function test_renaming_a_used_category_preserves_product_links_and_historical_names(): void
    {
        $variant = $this->stock();
        $invoice = $this->sale($variant);
        $id = $variant->product->category_id;
        $this->putJson(route('catalog.attributes.categories.update', $id), ['name' => '  أزياء عصرية  ', 'description' => 'وصف جديد'])->assertOk();
        $this->assertDatabaseHas('categories', ['id' => $id, 'name' => 'أزياء عصرية', 'description' => 'وصف جديد']);
        $this->assertSame($id, $variant->product->fresh()->category_id);
        $this->assertSame('ملابس', OpeningStockItem::firstOrFail()->category_name);
        $this->assertSame('منتج SHIRT', $invoice->items()->firstOrFail()->product_name);
        $this->deleteJson(route('catalog.attributes.categories.destroy', $id))->assertUnprocessable();
        $this->assertDatabaseHas('inventories', ['product_variant_id' => $variant->id, 'quantity' => 8, 'value_minor' => 16000]);
    }

    public function test_category_name_uniqueness_remains_scoped_to_its_existing_parent(): void
    {
        $parent = Category::create(['name' => 'أطفال']);
        $first = Category::create(['name' => 'قمصان', 'parent_id' => $parent->id]);
        Category::create(['name' => 'بناطيل', 'parent_id' => $parent->id]);
        $elsewhere = Category::create(['name' => 'آخر']);
        foreach ([['name' => 'بناطيل'], ['name' => '  '], ['name' => str_repeat('س', 101)],
            ['name' => 'قمصان', 'description' => str_repeat('س', 2001)], ['name' => 'قمصان', 'parent_id' => $elsewhere->id]] as $data) {
            $this->putJson(route('catalog.attributes.categories.update', $first), $data)->assertUnprocessable();
        }
        $this->putJson(route('catalog.attributes.categories.update', $elsewhere), ['name' => 'بناطيل'])->assertOk();
        $this->putJson(route('catalog.attributes.categories.update', $first), ['name' => 'قمصان'])->assertOk();
        $this->assertDatabaseHas('categories', ['id' => $first->id, 'parent_id' => $parent->id]);
    }

    public function test_category_deletion_protects_children_and_archived_products(): void
    {
        $parent = Category::create(['name' => 'رئيسي']);
        $child = Category::create(['name' => 'فرعي', 'parent_id' => $parent->id]);
        $this->deleteJson(route('catalog.attributes.categories.destroy', $parent))->assertUnprocessable();
        $this->assertDatabaseHas('categories', ['id' => $child->id, 'parent_id' => $parent->id]);
        Product::create(['name' => 'مؤرشف', 'code' => 'ARCHIVED', 'category_id' => $child->id])->delete();
        $this->deleteJson(route('catalog.attributes.categories.destroy', $child))->assertUnprocessable();
        $this->get(route('catalog.attributes.index', ['tab' => 'categories']))->assertOk()
            ->assertSee('مرتبط بمنتجات أو تصنيفات فرعية؛ لا يُحذف.');
    }

    public function test_an_unused_category_can_be_deleted_and_normal_forms_redirect_to_the_correct_tab(): void
    {
        $category = Category::create(['name' => 'قديم']);
        $this->put(route('catalog.attributes.categories.update', $category), ['name' => 'جديد'])->assertRedirect(route('catalog.attributes.index', ['tab' => 'categories']));
        $this->delete(route('catalog.attributes.categories.destroy', $category))->assertRedirect(route('catalog.attributes.index', ['tab' => 'categories']));
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        $size = Size::create(['name' => 'M', 'code' => 'M']);
        $this->put(route('catalog.attributes.sizes.update', $size), ['name' => 'وسط'])->assertRedirect(route('catalog.attributes.index', ['tab' => 'sizes']));
        $this->delete(route('catalog.attributes.sizes.destroy', $size))->assertRedirect(route('catalog.attributes.index', ['tab' => 'sizes']));
        $this->assertDatabaseMissing('sizes', ['id' => $size->id]);
    }

    public function test_attribute_mutations_require_catalog_permission(): void
    {
        $size = Size::create(['name' => 'M', 'code' => 'M']);
        $category = Category::create(['name' => 'ملابس']);
        $this->actingAs(User::factory()->create());
        $this->putJson(route('catalog.attributes.sizes.update', $size), ['name' => 'تغيير'])->assertForbidden();
        $this->deleteJson(route('catalog.attributes.sizes.destroy', $size))->assertForbidden();
        $this->putJson(route('catalog.attributes.categories.update', $category), ['name' => 'تغيير'])->assertForbidden();
        $this->deleteJson(route('catalog.attributes.categories.destroy', $category))->assertForbidden();
        $this->assertDatabaseHas('sizes', ['id' => $size->id, 'name' => 'M']);
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'ملابس']);
    }

    public function test_both_tabs_render_edit_delete_controls_and_shared_mobile_dialogs(): void
    {
        Size::create(['name' => 'M', 'code' => 'M']);
        Category::create(['name' => 'ملابس']);
        foreach (['sizes', 'categories'] as $tab) {
            $this->get(route('catalog.attributes.index', ['tab' => $tab]))->assertOk()
                ->assertSee('data-edit-attribute', false)->assertSee('data-delete-attribute', false)
                ->assertSee('attribute-edit-dialog')->assertSee('attribute-delete-dialog');
        }
    }
}
