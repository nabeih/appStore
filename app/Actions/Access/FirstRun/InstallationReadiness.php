<?php

namespace App\Actions\Access\FirstRun;

use App\Models\ApplicationInstallation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InstallationReadiness
{
    public function state(): ?ApplicationInstallation
    {
        if (! Schema::hasTable('application_installations')) {
            return null;
        }
        $states = ApplicationInstallation::limit(2)->useWritePdo()->get();

        return $states->count() === 1 && (int) $states->first()->id === 1 ? $states->first() : null;
    }

    public function available(): bool
    {
        return $this->canBootstrap($this->state());
    }

    public function canBootstrap(?ApplicationInstallation $state): bool
    {
        return $state && $state->completed_at === null && in_array($state->status, ['unprepared', 'pending'], true)
            && $this->isPristine();
    }

    public function isPristine(): bool
    {
        foreach (['users', 'roles', 'permissions', 'permission_role', 'product_code_counters', 'sales_controls'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }
        if (! Schema::hasColumns('product_code_counters', ['last_number', 'lock_version'])
            || ! Schema::hasColumns('sales_controls', ['activated_at', 'owner_id'])) {
            return false;
        }
        $ignored = ['application_installations', 'migrations', 'sessions', 'cache', 'cache_locks', 'product_code_counters', 'sales_controls'];
        $tables = array_diff(Schema::getTableListing(schema: Schema::getCurrentSchemaName(), schemaQualified: false), $ignored);
        $grammar = DB::connection()->getQueryGrammar();
        $query = DB::query()->useWritePdo();
        foreach (array_values($tables) as $index => $table) {
            $query->selectRaw('EXISTS (SELECT 1 FROM '.$grammar->wrapTable($table).') AS occupied_'.$index);
        }
        foreach ((array) $query->first() as $occupied) {
            if ((bool) $occupied) {
                return false;
            }
        }
        $counter = DB::table('product_code_counters')->useWritePdo()->where('id', 1)->first();
        $control = DB::table('sales_controls')->useWritePdo()->where('id', 1)->first();

        return $counter && (int) $counter->last_number === 0 && (int) $counter->lock_version === 0
            && DB::table('product_code_counters')->useWritePdo()->count() === 1
            && $control && $control->activated_at === null && $control->owner_id === null
            && DB::table('sales_controls')->useWritePdo()->count() === 1;
    }
}
