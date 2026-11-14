<?php

namespace App\Actions\Inventory;

use App\Support\Sales\Money;
use Generator;
use Illuminate\Database\Eloquent\Builder;

class ReadinessWorkbook
{
    public static function money(int|string|null $minor): ?array
    {
        return $minor === null ? null : ['number' => Money::format((int) $minor)];
    }

    public function inventory(Builder $query): array
    {
        return ['جاهزية المخزون' => $this->inventoryRows($query), 'أدلة المصادر' => $this->sourceRows($query)];
    }

    private function inventoryRows(Builder $query): Generator
    {
        yield ['الفرع', 'المنتج', 'اللون', 'المقاس', 'SKU', 'الكمية', 'كمية الحركات', 'فرق الكمية', 'القيمة المسجلة', 'القيمة المدعومة بالمصادر', 'حالة القيمة', 'الإجراء المقترح', 'أسباب المراجعة'];
        foreach ((clone $query)->lazyById(100, 'inventories.id', 'id')->chunk(100) as $stocks) {
            app(InventoryReadinessReport::class)->annotate(collect($stocks->all()));
            foreach ($stocks as $stock) {
                $row = $stock->readiness;
                yield [$stock->branch->name, $stock->productVariant->product->name, $stock->productVariant->color?->name,
                    $stock->productVariant->size?->name, $stock->productVariant->sku, (int) $stock->quantity,
                    $row['history_quantity'], $row['difference'], self::money($row['recorded_value_minor']), self::money($row['reconstructed_value_minor']),
                    $row['recorded_value_minor'] === null ? 'غير معتمدة' : 'مسجلة؛ راجع المطابقة', $row['recommended_action'], implode('؛ ', $row['issues'])];
            }
        }
    }

    private function sourceRows(Builder $query): Generator
    {
        yield ['الفرع', 'SKU', 'المستند', 'تاريخ المستند', 'حالة المستند', 'نوع الحركة', 'الكمية', 'قيمة الحركة', 'قيمة المصدر', 'القيد المالي', 'أسباب المراجعة'];
        foreach ((clone $query)->lazyById(100, 'inventories.id', 'id')->chunk(100) as $stocks) {
            app(InventoryReadinessReport::class)->annotate(collect($stocks->all()));
            foreach ($stocks as $stock) {
                foreach ($stock->readiness['sources'] as $source) {
                    yield [$stock->branch->name, $stock->productVariant->sku, $source['number'], $source['document_date'], $source['document_status'],
                        $source['kind'], (int) $source['quantity'], self::money($source['recorded_value_minor']), self::money($source['evidence_value_minor']),
                        $source['financial_posted'] ? 'مسجل' : 'غير مثبت', implode('؛ ', $source['issues'])];
                }
            }
        }
    }

    public function setup(iterable $reports): array
    {
        $sheets = ['مطابقة الأرصدة' => [['الفرع', 'الحساب', 'قيمة البضاعة', 'رصيد الدفتر', 'الفرق', 'حالة المطابقة']],
            'الأصناف' => [['الفرع', 'المنتج', 'SKU', 'الكمية', 'القيمة المسجلة', 'القيمة المدعومة', 'أسباب المراجعة']],
            'حسابات القبض' => [['الفرع', 'الحساب', 'النوع', 'رصيد الدفتر', 'بداية معتمدة']],
            'مستندات الافتتاح' => [['الفرع', 'رقم المستند', 'الحالة', 'التاريخ', 'الكمية', 'القيمة']],
            'موانع الإنهاء' => [['الفرع', 'السبب']],
            'المشتريات' => [['الفرع', 'الفاتورة', 'الحالة', 'التاريخ', 'المورد', 'القيمة', 'المسدد', 'الدفعات', 'المستحق', 'مستحق الدفتر', 'القيد المالي']],
            'الموردون' => [['الفرع', 'رقم المورد', 'المستحق المسجل', 'مستحق الدفتر', 'الفرق']],
            'ذمم العملاء' => [['الفرع', 'الذمم المدينة', 'المبالغ لصالح العملاء']],
            'دفعات الشراء' => [['الفرع', 'الفاتورة', 'رقم الدفعة', 'المبلغ', 'الحالة', 'التاريخ', 'الطريقة', 'المرجع', 'القيد المالي']],
            'تفاصيل المراجعة' => [['الفرع', 'النوع', 'تفاصيل للقراءة فقط']]];
        foreach ($reports as $report) {
            foreach ($report['reconciliation'] as $code => $row) {
                $sheets['مطابقة الأرصدة'][] = [$report['branch'], $code === 'inventory' ? 'المخزون المتاح' : 'قيد الفحص', self::money($row['stock_minor']), self::money($row['ledger_minor']), self::money($row['difference_minor']), $row['complete'] ? 'مطابق' : 'يحتاج مراجعة'];
            }
            foreach ($report['inventory'] as $row) {
                $sheets['الأصناف'][] = [$report['branch'], $row['product'], $row['sku'], $row['quantity'], self::money($row['recorded_value_minor']), self::money($row['reconstructed_value_minor']), implode('؛ ', $row['issues'])];
            }
            foreach ($report['accounts'] as $row) {
                $sheets['حسابات القبض'][] = [$report['branch'], $row['name'], $row['type'], self::money($row['ledger_minor']), $row['opening_known'] ? 'نعم' : 'غير معروفة'];
            }
            foreach ($report['opening_documents'] as $row) {
                $sheets['مستندات الافتتاح'][] = [$report['branch'], $row['number'], $row['status'], $row['date'], $row['quantity'], ['number' => (string) $row['value']]];
            }
            foreach ($report['blockers'] as $row) {
                $sheets['موانع الإنهاء'][] = [$report['branch'], $row['message']];
            }
            foreach ($report['purchases'] as $row) {
                $sheets['المشتريات'][] = [$report['branch'], $row['number'], $row['status'], $row['date'], $row['supplier'], self::money($row['total_minor']), self::money($row['paid_minor']), self::money($row['payments_minor']), self::money($row['due_minor']), self::money($row['ledger_due_minor']), $row['financial_posted'] ? 'مسجل' : 'غير مثبت'];
                foreach ($row['payments'] as $payment) {
                    $sheets['دفعات الشراء'][] = [$report['branch'], $row['number'], (string) $payment['id'], self::money($payment['amount_minor']), $payment['status'], $payment['date'], $payment['method'], $payment['reference'], $payment['financial_posted'] ? 'مسجل' : 'غير مثبت'];
                }
            }
            foreach ($report['suppliers'] as $row) {
                $sheets['الموردون'][] = [$report['branch'], (string) $row['supplier_id'], self::money($row['operational_minor']), self::money($row['ledger_minor']), self::money($row['difference_minor'])];
            }
            $sheets['ذمم العملاء'][] = [$report['branch'], self::money($report['receivables_minor']), self::money($report['customer_credit_minor'])];
            foreach (['quarantine_issues', 'document_movement_issues'] as $kind) {
                foreach ($report[$kind] as $row) {
                    $sheets['تفاصيل المراجعة'][] = [$report['branch'], $kind, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
                }
            }
        }

        return $sheets;
    }

    public function transition(array $report): array
    {
        $sheets = ['مستندات الانتقال' => [['نوع المصدر', 'رقم المصدر', 'الفرع', 'التاريخ', 'الوصف', 'القيد المالي']],
            'المشتريات' => [['الفاتورة', 'الفرع', 'المورد', 'التاريخ', 'قيمة الفاتورة', 'المسدد المسجل', 'الدفعات المسجلة', 'المتبقي']],
            'أصناف المشتريات' => [['الفاتورة', 'SKU', 'كمية المستند', 'قيمة المستند', 'كمية الحركة', 'قيمة الحركة']],
            'دفعات الموردين' => [['الفاتورة', 'رقم الدفعة', 'المبلغ', 'التاريخ', 'الطريقة المسجلة', 'المرجع', 'سبب المراجعة']],
            'أرصدة الحسابات' => [['الحساب', 'رقم الحساب', 'التاريخ', 'مدين', 'دائن']], 'ملاحظات' => [['تنبيه', 'التفاصيل']]];
        foreach ($report['documents'] as $row) {
            $sheets['مستندات الانتقال'][] = [$row['kind'], (string) $row['id'], (string) $row['branch_id'], $row['date'], $row['label'], $row['posted'] ? 'مسجل' : 'غير مسجل؛ يحتاج مراجعة منفصلة'];
        }
        foreach ($report['invoices'] as $row) {
            $sheets['المشتريات'][] = [$row['number'], (string) $row['branch_id'], $row['supplier'], $row['date'], self::money($row['invoice_minor']), self::money($row['paid_cache_minor']), self::money($row['payments_minor']), self::money($row['remaining_minor'])];
            foreach ($row['items'] as $item) {
                $sheets['أصناف المشتريات'][] = [$row['number'], $item['sku'], $item['document_quantity'], self::money($item['document_value_minor']), $item['movement_quantity'], self::money($item['movement_value_minor'])];
            }
            foreach ($row['payments'] as $payment) {
                $sheets['دفعات الموردين'][] = [$row['number'], (string) $payment['id'], self::money($payment['amount_minor']), $payment['recorded_date'], $payment['recorded_method'], $payment['reference'], $payment['reason']];
            }
        }
        foreach ($report['recorded_account_openings'] as $row) {
            $row = (array) $row;
            $sheets['أرصدة الحسابات'][] = [$row['name'], (string) $row['account_id'], (string) $row['transaction_date'], self::money($row['debit_minor']), self::money($row['credit_minor'])];
        }
        $sheets['ملاحظات'][] = ['الحالة', 'للقراءة فقط؛ ليس اعتمادًا. لا تفترض أن البيانات تدريبية. مصدرها قاعدة النسخة التي نُزّل منها التقرير.'];
        foreach ($report['unresolved'] as $issue) {
            $sheets['ملاحظات'][] = ['يتطلب مراجعة', $issue];
        }

        return $sheets;
    }
}
