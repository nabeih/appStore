@php
    $setupData = [
        'name' => $inventory->productVariant?->product?->name ?? 'منتج',
        'code' => $inventory->productVariant?->product?->code ?? '—',
        'branch' => $inventory->branch?->name ?? '—',
        'color' => $inventory->productVariant?->color?->name ?? '—',
        'size' => $inventory->productVariant?->size?->name ?? '—',
        'category' => $inventory->productVariant->product->category?->name ?? '—',
        'category_id' => $inventory->productVariant->product->category_id,
        'color_id' => $inventory->productVariant->color_id,
        'size_id' => $inventory->productVariant->size_id,
        'quantity' => $inventory->quantity,
        'selling_price' => $inventory->productVariant->selling_price,
        'revision' => \App\Support\Inventory\Setup\InventoryState::revision($inventory, $inventory->productVariant, $inventory->productVariant->product),
        'update_url' => route('inventory.update', $inventory),
        'delete_url' => route('inventory.destroy', $inventory),
    ];
    if (auth()->user()->can('sales.view_cost')) {
        $setupData['average_cost'] = (string) $inventory->average_cost;
    }
    $details = [
        'name' => $setupData['name'],
        'description' => $inventory->productVariant->product->description,
        'price' => $inventory->productVariant->selling_price,
        'color_id' => $inventory->productVariant->color_id,
        'size_id' => $inventory->productVariant->size_id,
        'variant_id' => $inventory->productVariant->id,
        'swatch_manage_url' => route('catalog.swatches.index', $inventory->productVariant->product_id),
        'url' => url('/api/products/'.$inventory->productVariant->product_id),
    ];
@endphp
<td class="inventory-setup-actions" data-inventory-details="{{ json_encode($setupData) }}">
    @can('inventory.manage_catalog')
        <button type="button" class="btn btn-outline btn-sm" data-product-details="{{ json_encode($details) }}">بيانات المنتج</button>
    @endcan
    @if($setupChangesEnabled && auth()->user()->can('sales.view_cost'))
        @can('inventory.correct_setup')
            <button type="button" class="btn btn-outline btn-sm" data-inventory-action="edit" aria-label="تصحيح افتتاحي {{ $setupData['name'] }}">تصحيح أول المدة</button>
            <button type="button" class="btn btn-danger btn-sm" data-inventory-action="delete" aria-label="حذف {{ $setupData['name'] }} من {{ $setupData['branch'] }}">حذف افتتاحي</button>
        @endcan
    @endif
</td>
