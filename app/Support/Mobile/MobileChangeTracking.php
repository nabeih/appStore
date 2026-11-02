<?php

namespace App\Support\Mobile;

use Illuminate\Support\Facades\DB;

class MobileChangeTracking
{
    /** @var array<string, array{string, string|null}> */
    public const DIRECT = [
        'products' => ['products', null], 'product_variants' => ['variants', null],
        'product_images' => ['images', null], 'product_color_swatches' => ['swatches', null],
        'categories' => ['categories', null], 'colors' => ['colors', null], 'sizes' => ['sizes', null],
        'branches' => ['branches', 'id'], 'inventories' => ['stocks', 'branch_id'],
        'customers' => ['customers', null], 'suppliers' => ['suppliers', null],
        'sale_invoices' => ['sales', 'branch_id'], 'customer_receipts' => ['receipts', 'branch_id'],
        'financial_accounts' => ['accounts', 'branch_id'], 'purchase_invoices' => ['purchases', 'branch_id'],
        'opening_stock_entries' => ['openings', 'branch_id'],
    ];

    /** @return list<string> */
    public static function triggerNames(): array
    {
        $names = [];
        foreach ([...array_keys(self::DIRECT), 'sale_items', 'purchase_invoice_items', 'opening_stock_items', 'journal_lines'] as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
                $names[] = 'mobile_'.substr(hash('sha256', $table.'_'.$event), 0, 20);
            }
        }

        return $names;
    }

    public function install(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        $existing = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
            ->whereIn('TRIGGER_NAME', self::triggerNames())->get()->keyBy('TRIGGER_NAME');
        foreach ([...array_keys(self::DIRECT), 'sale_items', 'purchase_invoice_items', 'opening_stock_items', 'journal_lines'] as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
                $name = 'mobile_'.substr(hash('sha256', $table.'_'.$event), 0, 20);
                $body = '';
                foreach ($event === 'UPDATE' ? ['OLD', 'NEW'] : [$event === 'DELETE' ? 'OLD' : 'NEW'] as $row) {
                    $body .= $this->rowEvents($table, $row);
                }
                $previous = $existing->get($name);
                if ($previous) {
                    $normalize = fn (string $sql): string => strtolower(preg_replace('/\\s+/', '', $sql));
                    if ($previous->EVENT_OBJECT_TABLE !== $table || $previous->EVENT_MANIPULATION !== $event
                        || $previous->ACTION_TIMING !== 'AFTER' || $normalize($previous->ACTION_STATEMENT) !== $normalize("BEGIN {$body} END")) {
                        throw new \RuntimeException('Unexpected mobile tracking definition; stop and review the partial migration.');
                    }

                    continue;
                }
                DB::unprepared("CREATE TRIGGER `{$name}` AFTER {$event} ON `{$table}` FOR EACH ROW BEGIN {$body} END");
            }
        }
        $this->backfill();
    }

    private function emit(string $entity, string $record, string $branch = '0'): string
    {
        return "IF ({$record}) IS NOT NULL THEN
            INSERT INTO mobile_sync_clock (id, version) VALUES (1, 1) ON DUPLICATE KEY UPDATE version = version + 1;
            INSERT INTO mobile_sync_changes (id, entity, record_id, branch_id)
            SELECT version, '{$entity}', ({$record}), COALESCE(({$branch}), 0) FROM mobile_sync_clock WHERE id = 1;
            END IF; ";
    }

    private function rowEvents(string $table, string $row): string
    {
        $sql = '';
        if (isset(self::DIRECT[$table])) {
            [$entity, $branch] = self::DIRECT[$table];
            $sql .= $this->emit($entity, "{$row}.id", $branch ? "{$row}.{$branch}" : '0');
        }
        if (in_array($table, ['product_variants', 'product_images', 'product_color_swatches'], true)) {
            $sql .= $this->emit('products', "{$row}.product_id");
        }
        if ($table === 'inventories') {
            $sql .= $this->emit('products', "(SELECT product_id FROM product_variants WHERE id = {$row}.product_variant_id)", "{$row}.branch_id");
            $sql .= $this->emit('variants', "{$row}.product_variant_id", "{$row}.branch_id");
        }
        foreach (['sale_items' => ['sales', 'sale_invoices', 'sale_invoice_id'],
            'purchase_invoice_items' => ['purchases', 'purchase_invoices', 'purchase_invoice_id'],
            'opening_stock_items' => ['openings', 'opening_stock_entries', 'opening_stock_entry_id']] as $child => [$entity, $parent, $key]) {
            if ($table === $child) {
                $sql .= $this->emit($entity, "{$row}.{$key}", "(SELECT branch_id FROM {$parent} WHERE id = {$row}.{$key})");
            }
        }
        if ($table === 'journal_lines') {
            $sql .= $this->emit('customer_balances', "{$row}.customer_id", "(SELECT branch_id FROM journal_entries WHERE id = {$row}.journal_entry_id)");
        }

        return $sql;
    }

    private function backfill(): void
    {
        foreach (self::DIRECT as $table => [$entity, $branch]) {
            DB::table($table)->orderBy('id')->chunkById(500, function ($rows) use ($entity, $branch): void {
                DB::transaction(function () use ($rows, $entity, $branch): void {
                    DB::table('mobile_sync_clock')->insertOrIgnore(['id' => 1, 'version' => 0]);
                    $version = (int) DB::table('mobile_sync_clock')->where('id', 1)->lockForUpdate()->value('version');
                    $changes = [];
                    foreach ($rows as $row) {
                        $changes[] = ['id' => ++$version, 'entity' => $entity, 'record_id' => $row->id, 'branch_id' => $branch ? ($row->{$branch} ?? 0) : 0];
                    }
                    DB::table('mobile_sync_clock')->where('id', 1)->update(['version' => $version]);
                    DB::table('mobile_sync_changes')->insert($changes);
                });
            });
        }
        DB::table('journal_lines as lines')->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->whereNotNull('lines.customer_id')->select('lines.customer_id', 'entries.branch_id')->distinct()->orderBy('lines.customer_id')
            ->get()->each(function ($row): void {
                DB::transaction(function () use ($row): void {
                    DB::table('mobile_sync_clock')->insertOrIgnore(['id' => 1, 'version' => 0]);
                    DB::table('mobile_sync_clock')->where('id', 1)->increment('version');
                    DB::table('mobile_sync_changes')->insert(['id' => DB::table('mobile_sync_clock')->where('id', 1)->value('version'),
                        'entity' => 'customer_balances', 'record_id' => $row->customer_id, 'branch_id' => $row->branch_id]);
                });
            });
    }
}
