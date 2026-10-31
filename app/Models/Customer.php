<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'phone', 'address', 'notes'];

    public function invoices(): HasMany
    {
        return $this->hasMany(SaleInvoice::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(CustomerReceipt::class);
    }
}
