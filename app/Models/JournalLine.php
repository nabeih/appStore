<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['journal_entry_id', 'financial_account_id', 'customer_id', 'sale_invoice_id', 'sale_return_id', 'debit_minor', 'credit_minor', 'supplier_id', 'purchase_invoice_id', 'expense_id'];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }
}
