<?php

namespace App\Actions\Search;

use App\Models\Category;
use App\Models\Color;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\FinancialAccount;
use App\Models\OpeningStockEntry;
use App\Models\PurchaseInvoice;
use App\Models\SaleExchange;
use App\Models\SaleInvoice;
use App\Models\SaleReturn;
use App\Models\Size;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class GlobalSearchAction
{
    public const LABELS = ['products' => 'المنتجات والأصناف', 'customers' => 'العملاء', 'suppliers' => 'الموردون', 'sales' => 'فواتير المبيعات',
        'purchases' => 'فواتير الشراء', 'openings' => 'الرصيد الافتتاحي', 'returns' => 'مرتجعات المبيعات', 'exchanges' => 'الاستبدالات',
        'receipts' => 'سندات العملاء', 'supplier_payments' => 'دفعات الموردين', 'accounts' => 'حسابات القبض', 'categories' => 'التصنيفات', 'colors' => 'الألوان', 'sizes' => 'المقاسات'];

    public function __construct(private ModuleSearch $search) {}

    /** @return array<string,string> */
    public function allowed(User $actor): array
    {
        $can = fn (array $abilities): bool => collect($abilities)->every(fn (string $ability): bool => $actor->can($ability));
        $requirements = [
            'products' => ['catalog.search', 'inventory.view'],
            'customers' => ['sales.customers'], 'suppliers' => ['sales.view_cost', 'purchases.view'], 'sales' => ['sales.view'],
            'purchases' => ['sales.view_cost', 'purchases.view'], 'openings' => ['inventory.correct_setup', 'sales.view_cost'],
            'returns' => ['sales.view'], 'exchanges' => ['sales.view'], 'receipts' => ['sales.customers', 'sales.collect'],
            'supplier_payments' => ['sales.view_cost', 'purchases.view'], 'accounts' => ['sales.manage_accounts'],
            'categories' => ['inventory.manage_catalog'], 'colors' => ['inventory.manage_catalog'], 'sizes' => ['inventory.manage_catalog'],
        ];

        return array_filter(self::LABELS, fn (string $type): bool => $can($requirements[$type]), ARRAY_FILTER_USE_KEY);
    }

    public function execute(User $actor, array $filters, bool $suggestions = false): array
    {
        $allowed = $this->allowed($actor);
        $type = $filters['type'] ?? null;
        if ($type) {
            abort_unless(isset($allowed[$type]), 403);
        }
        $term = SearchTerm::normalize($filters['q'] ?? '');
        if ($term === '' && ($suggestions || ! $type)) {
            return ['groups' => [], 'types' => $allowed, 'q' => $term];
        }
        $groups = [];
        foreach ($type ? [$type => $allowed[$type]] : $allowed as $key => $label) {
            $query = $this->query($key, $filters, $actor);
            if ($suggestions || ! $type) {
                $records = $query->limit(6)->get();
                $more = $records->count() > 5;
                $entries = $records->take(5)->map(fn (Model $record): array => $this->describe($key, $record, $actor));
                if ($entries->isEmpty()) {
                    continue;
                }
                $groups[] = ['type' => $key, 'label' => $label, 'entries' => $entries, 'more' => $more,
                    'url' => route('search.index', array_merge($filters, ['type' => $key, 'page' => 1]))];
            } else {
                $entries = $query->paginate(20)->withQueryString()->through(fn (Model $record): array => $this->describe($key, $record, $actor));
                $groups[] = ['type' => $key, 'label' => $label, 'entries' => $entries, 'more' => false, 'url' => null];
            }
        }

        return ['groups' => $groups, 'types' => $allowed, 'q' => $term];
    }

    private function query(string $type, array $filters, User $actor): Builder
    {
        $term = SearchTerm::normalize($filters['q'] ?? '');
        $mode = $filters['match'] ?? 'contains';
        $query = match ($type) {
            'products' => app(ProductSearchAction::class)->query($actor, $filters),
            'customers' => $this->search->contacts(Customer::query(), $term),
            'suppliers' => $this->search->contacts(Supplier::query(), $term, true),
            'sales' => $this->search->documents($this->search->branches(SaleInvoice::query(), $actor), $filters, 'SL', ['customer_name', 'cashier_name', 'notes']),
            'purchases' => $this->search->documents($this->search->branches(PurchaseInvoice::query(), $actor), $filters, 'PI', ['supplier_name', 'supplier_invoice_number', 'notes']),
            'openings' => $this->search->documents($this->search->branches(OpeningStockEntry::query(), $actor), $filters, 'OS', ['notes']),
            'receipts' => $this->search->receipts(CustomerReceipt::query(), $filters, $actor),
            'supplier_payments' => $this->search->receipts(SupplierPayment::query(), $filters, $actor, true),
            'accounts' => $this->search->accounts(FinancialAccount::whereIn('type', ['cash', 'bank', 'wallet']), $filters, $actor),
            'categories' => SearchTerm::apply(Category::query(), $term, ['name', 'description'], [], $mode),
            'colors' => SearchTerm::apply(Color::query(), $term, ['name'], ['code'], $mode),
            'sizes' => SearchTerm::apply(Size::query(), $term, ['name'], ['code'], $mode),
            'returns' => $this->adjustments(SaleReturn::query(), $filters, $actor, 'SR', ['invoice']),
            'exchanges' => $this->adjustments(SaleExchange::query(), $filters, $actor, 'EX', ['originalInvoice', 'newInvoice']),
        };
        if ($type === 'products' && ! ($actor->can('inventory.manage_catalog') && $actor->can('sales.view_cost'))) {
            $query->where('quantity', '>', 0)->whereHas('branch', fn (Builder $branch) => $branch->where('is_active', true));
        }

        return $this->search->sort($query, $filters, in_array($type, ['customers', 'suppliers', 'accounts', 'categories', 'colors', 'sizes'], true) ? 'name' : null);
    }

    private function adjustments(Builder $query, array $filters, User $actor, string $prefix, array $relations): Builder
    {
        $this->search->branches($query, $actor);
        $term = SearchTerm::normalize($filters['q'] ?? '');
        if ($term !== '') {
            $query->where(function (Builder $document) use ($filters, $prefix, $relations, $term): void {
                SearchTerm::apply($document, $term, ['reason']);
                if ($id = SearchTerm::documentId($term, $prefix)) {
                    $document->orWhereKey($id);
                }
                foreach ($relations as $relation) {
                    $document->orWhereHas($relation, fn (Builder $invoice) => $this->search->documents($invoice, ['q' => $term, 'match' => $filters['match'] ?? 'contains'], 'SL', ['customer_name', 'cashier_name']));
                }
            });
        }

        return $this->search->filters($query, $filters, 'transaction_date');
    }

    private function describe(string $type, Model $record, User $actor): array
    {
        $title = $detail = '';
        $url = '';
        if ($type === 'products') {
            $variant = $record->productVariant;
            $title = $variant->product->name;
            $detail = $variant->sku.' — '.$variant->color->name.' / '.$variant->size->name.' — '.$record->branch->name;
            $route = $actor->can('sales.sell') && ! $actor->can('inventory.manage_catalog') ? 'sales.cashier' : 'inventory.index';
            $url = route($route, ['q' => $variant->sku, 'branch_id' => $record->branch_id]);
        } elseif (in_array($type, ['customers', 'suppliers'], true)) {
            $title = $record->name;
            $detail = self::LABELS[$type];
            $url = route($type.'.show', $record->id);
        } elseif ($type === 'accounts') {
            $title = $record->name;
            $detail = match ($record->type) {
                'cash' => 'درج نقدي','bank' => 'حساب بنكي',default => 'محفظة'
            };
            $url = route('finance.accounts.index', ['q' => $record->code, 'branch_id' => $record->branch_id]);
        } elseif (in_array($type, ['categories', 'colors', 'sizes'], true)) {
            $title = $record->name;
            $detail = $type === 'categories' ? 'تصنيف' : $record->code;
            $url = route('catalog.attributes.index', ['tab' => $type, 'q' => $record->name]);
        } elseif ($type === 'supplier_payments') {
            $title = 'دفعة مورد #'.$record->id;
            $detail = $record->payment_date->format('Y-m-d');
            $url = route('suppliers.show', ['supplier' => $record->supplier_id, 'payments_q' => 'SP-'.$record->id]).'#supplier-payment-'.$record->id;
        } else {
            $title = $record->number;
            $detail = self::LABELS[$type];
            $url = match ($type) {
                'sales' => route('sales.invoices.show', $record), 'purchases' => route('purchases.invoices.show', $record),
                'openings' => route('inventory.opening-stock.show', $record), 'returns' => route('sales.returns.show', $record),
                'exchanges' => route('sales.exchanges.show', $record), 'receipts' => route('customers.receipts.show', $record),
            };
        }

        $result = ['id' => $record->id, 'type' => $type, 'title' => $title, 'detail' => $detail, 'url' => $url];
        if ($type === 'products') {
            $product = app(ProductSearchAction::class)->describe($record, $actor);
            unset($product['average_cost'], $product['unit_price']);
            $result['product'] = $product;
        }

        return $result;
    }
}
