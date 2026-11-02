<?php

namespace App\Support\Mobile;

use App\Models\Product;
use App\Support\Sales\OperationContext;
use Illuminate\Database\Eloquent\Model;

class MobileRevision
{
    public static function product(Product $product): string
    {
        $product->loadMissing('variants');

        return hash_hmac('sha256', OperationContext::hash(['product' => $product->getAttributes(),
            'variants' => $product->variants->sortBy('id')->map(fn ($variant): array => $variant->getAttributes())->values()->all()]), (string) config('app.key'));
    }

    public static function of(Model $model): string
    {
        return hash_hmac('sha256', $model->getTable().'|'.OperationContext::hash($model->getAttributes()), (string) config('app.key'));
    }
}
