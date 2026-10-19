<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مخزون المحل</title>

    <style>
        body {
            font-family: Tahoma, Arial, sans-serif;
            background: #f5f6fa;
            padding: 30px;
        }

        h1 {
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        th,
        td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: right;
        }

        th {
            background: #eef0f5;
        }
    </style>
</head>

<body>

    <h1>مخزون المحل</h1>

    <table>
        <thead>
            <tr>
                <th>المنتج</th>
                <th>الكود</th>
                <th>التصنيف</th>
                <th>اللون</th>
                <th>المقاس</th>
                <th>الكمية</th>
                <th>تكلفة الشراء</th>
                <th>سعر البيع</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($inventories as $inventory)
                @php
                    $variant = $inventory->productVariant;
                    $product = $variant?->product;
                @endphp

                <tr>
                    <td>{{ $product?->name }}</td>
                    <td>{{ $product?->code }}</td>
                    <td>{{ $product?->category?->name }}</td>
                    <td>{{ $variant?->color?->name }}</td>
                    <td>{{ $variant?->size?->name }}</td>
                    <td>{{ $inventory->quantity }}</td>
                    <td>{{ $inventory->average_cost }}</td>
                    <td>{{ $variant?->selling_price }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        لا توجد منتجات في المخزون حاليًا.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div style="margin-top: 20px;">
        {{ $inventories->links() }}
    </div>

</body>

</html>