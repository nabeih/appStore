<?php

namespace App\Support\Sales;

use App\Actions\Search\SearchTerm;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\SaleExchange;
use App\Models\SaleInvoice;
use App\Models\SaleReturn;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

class SalesReports
{
    public static function branches(User $actor, bool $activeOnly = true): Collection
    {
        return Branch::query()->when($activeOnly, fn ($query) => $query->where('is_active', true))->when(! SalesAccess::allows($actor, 'sales.all_branches'), fn ($query) => $query->whereKey($actor->branch_id ?? 0))->orderBy('name')->get();
    }

    private function lines(array $filters, bool $asOf = false): Builder
    {
        return DB::table('journal_lines as lines')->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('financial_accounts as accounts', 'accounts.id', '=', 'lines.financial_account_id')
            ->when($filters['branch_id'] ?? null, fn ($query, $id) => $query->where('entries.branch_id', $id))
            ->when(! $asOf && ! empty($filters['from']), fn ($query) => $query->where('entries.transaction_date', '>=', $filters['from']))
            ->where('entries.transaction_date', '<', CarbonImmutable::parse($filters['to'])->addDay()->toDateString());
    }

    private function amount(string $code, array $filters, bool $asOf = false): int
    {
        return (int) $this->lines($filters, $asOf)->where('accounts.code', $code)
            ->selectRaw('COALESCE(SUM(lines.debit_minor),0) - COALESCE(SUM(lines.credit_minor),0) AS amount')->value('amount');
    }

    public function summary(array $filters): array
    {
        $sales = -$this->amount('sales', $filters);
        $returns = $this->amount('sales_returns', $filters);
        $cogs = $this->amount('cogs', $filters);
        $loss = $this->amount('inventory_loss', $filters);
        $receipts = $this->lines($filters)->leftJoin('journal_entries as original', 'entries.reversal_of_id', '=', 'original.id')
            ->whereIn('accounts.type', ['cash', 'bank', 'wallet'])
            ->where(fn ($query) => $query->where('entries.source_type', CustomerReceipt::class)->orWhere('original.source_type', CustomerReceipt::class))
            ->groupBy('accounts.id', 'accounts.name', 'accounts.type')->select('accounts.id', 'accounts.name', 'accounts.type')
            ->selectRaw('SUM(lines.debit_minor) AS received_minor, SUM(lines.credit_minor) AS refunded_minor')->get();
        $quantities = DB::table('stock_movements')->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->whereBetween('effective_date', [$filters['from'], $filters['to']])
            ->whereIn('movement_type', ['sale', 'sale_reversal', 'sale_return', 'sale_return_reversal'])
            ->selectRaw("COALESCE(SUM(CASE WHEN movement_type IN ('sale','sale_reversal') THEN -quantity_change ELSE 0 END),0) AS sold")
            ->selectRaw("COALESCE(SUM(CASE WHEN movement_type IN ('sale_return','sale_return_reversal') THEN quantity_change ELSE 0 END),0) AS returned")
            ->selectRaw("COALESCE(SUM(CASE WHEN stock_bucket = 'available' THEN quantity_change ELSE 0 END),0) AS available_change")->first();

        return ['sales_minor' => $sales, 'returns_minor' => $returns, 'net_sales_minor' => $sales - $returns, 'cogs_minor' => $cogs,
            'gross_profit_minor' => $sales - $returns - $cogs, 'inventory_loss_minor' => $loss,
            'receivables_minor' => $this->amount('receivables', $filters, true), 'customer_credit_minor' => -$this->amount('customer_credit', $filters, true),
            'receipts' => $receipts, 'sold_quantity' => (int) $quantities->sold, 'returned_quantity' => (int) $quantities->returned, 'available_change' => (int) $quantities->available_change];
    }

    public function customerStatement(Customer $customer, array $filters): array
    {
        $query = $this->lines($filters, true)->where('lines.customer_id', $customer->id)
            ->whereIn('accounts.code', ['receivables', 'customer_credit']);
        $grouped = (clone $query)->leftJoin('journal_entries as original', 'entries.reversal_of_id', '=', 'original.id')
            ->where('entries.transaction_date', '>=', $filters['from'])
            ->groupBy('entries.id', 'entries.transaction_date', 'entries.description', 'entries.source_type', 'entries.source_id', 'original.id', 'original.source_type', 'original.source_id')
            ->select('entries.id', 'entries.transaction_date', 'entries.description')
            ->selectRaw('CASE WHEN original.id IS NULL THEN entries.source_type ELSE original.source_type END AS source_type')
            ->selectRaw('CASE WHEN original.id IS NULL THEN entries.source_id ELSE original.source_id END AS source_id')
            ->selectRaw("SUM(CASE WHEN accounts.code = 'receivables' THEN lines.debit_minor ELSE 0 END) - SUM(CASE WHEN accounts.code = 'receivables' THEN lines.credit_minor ELSE 0 END) AS debt_delta")
            ->selectRaw("SUM(CASE WHEN accounts.code = 'customer_credit' THEN lines.credit_minor ELSE 0 END) - SUM(CASE WHEN accounts.code = 'customer_credit' THEN lines.debit_minor ELSE 0 END) AS credit_delta");
        $opening = $this->statementBalances((clone $query)->where('entries.transaction_date', '<', $filters['from']));
        $totals = $this->statementBalances(clone $query);
        $running = DB::query()->fromSub($grouped, 'activity')->select('activity.*')
            ->selectRaw('SUM(debt_delta) OVER (ORDER BY transaction_date,id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) + ? AS debt_balance', [$opening['debt']])
            ->selectRaw('SUM(credit_delta) OVER (ORDER BY transaction_date,id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) + ? AS credit_balance', [$opening['credit']]);
        $visible = DB::query()->fromSub($running, 'statement');
        $term = SearchTerm::normalize($filters['entry_q'] ?? '');
        if ($term !== '') {
            $visible->where(function (Builder $rows) use ($term): void {
                if (mb_strlen($term) < 2) {
                    $rows->where('description', $term);
                } else {
                    $rows->whereRaw("description LIKE ? ESCAPE '!'", [SearchTerm::pattern($term)]);
                }
                foreach ([SaleInvoice::class => 'SL', SaleReturn::class => 'SR', SaleExchange::class => 'EX', CustomerReceipt::class => 'RC'] as $type => $prefix) {
                    if ($id = SearchTerm::documentId($term, $prefix)) {
                        $rows->orWhere(fn (Builder $source) => $source->where('source_type', $type)->where('source_id', $id));
                    }
                }
            });
        }
        $rows = $visible->orderBy('transaction_date')->orderBy('id')->paginate(50)->withQueryString()
            ->through(function (stdClass $row): stdClass {
                foreach (['debt_delta', 'credit_delta', 'debt_balance', 'credit_balance'] as $field) {
                    $row->$field = Money::integer((string) $row->$field);
                }

                return $row;
            });

        return ['rows' => $rows, 'openingDebt' => $opening['debt'], 'openingCredit' => $opening['credit'],
            'debt' => $totals['debt'], 'credit' => $totals['credit']];
    }

    /** @return array{debt: int, credit: int} */
    private function statementBalances(Builder $query): array
    {
        $balance = $query->selectRaw("COALESCE(SUM(CASE WHEN accounts.code = 'receivables' THEN lines.debit_minor ELSE 0 END),0) - COALESCE(SUM(CASE WHEN accounts.code = 'receivables' THEN lines.credit_minor ELSE 0 END),0) AS debt")
            ->selectRaw("COALESCE(SUM(CASE WHEN accounts.code = 'customer_credit' THEN lines.credit_minor ELSE 0 END),0) - COALESCE(SUM(CASE WHEN accounts.code = 'customer_credit' THEN lines.debit_minor ELSE 0 END),0) AS credit")->first();

        return ['debt' => (int) $balance->debt, 'credit' => (int) $balance->credit];
    }

    public static function sourceUrl(string $type, int $id): ?string
    {
        return match ($type) {
            SaleInvoice::class => route('sales.invoices.show', $id),
            SaleReturn::class => route('sales.returns.show', $id),
            SaleExchange::class => route('sales.exchanges.show', $id),
            CustomerReceipt::class => route('customers.receipts.show', $id),
            default => null,
        };
    }
}
