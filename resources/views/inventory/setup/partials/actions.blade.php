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
        'average_cost' => (string) $inventory->average_cost,
        'selling_price' => $inventory->productVariant->selling_price,
        'revision' => \App\Support\Inventory\Setup\InventoryState::revision($inventory, $inventory->productVariant, $inventory->productVariant->product),
        'update_url' => route('inventory.update', $inventory),
        'delete_url' => route('inventory.destroy', $inventory),
    ];
@endphp
<td class="inventory-setup-actions" data-inventory-details="{{ json_encode($setupData) }}">
    <button type="button" class="btn btn-outline btn-sm" data-inventory-action="edit" aria-label="تعديل {{ $setupData['name'] }}">تعديل</button>
    <button type="button" class="btn btn-danger btn-sm" data-inventory-action="delete" aria-label="حذف {{ $setupData['name'] }} من {{ $setupData['branch'] }}">حذف</button>
</td>