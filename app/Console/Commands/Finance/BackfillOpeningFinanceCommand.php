<?php

namespace App\Console\Commands\Finance;

use App\Actions\Finance\BackfillOpeningFinance;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class BackfillOpeningFinanceCommand extends Command
{
    protected $signature = 'app:opening-finance-backfill {--actor=} {--branch=} {--document=*} {--evidence=} {--reason=} {--dry-run} {--execute} {--confirmed} {--revision=}';

    protected $description = 'Preview missing opening finance only; execution requires an enabled transition, owner, explicit confirmation and reviewed revision.';

    public function handle(BackfillOpeningFinance $backfill): int
    {
        try {
            $data = Validator::make(['actor' => $this->option('actor'), 'branch' => $this->option('branch'), 'documents' => $this->option('document'),
                'evidence' => $this->option('evidence')], ['actor' => 'required|integer|min:1', 'branch' => 'required|integer|min:1',
                    'documents' => 'required|array|min:1|max:100', 'documents.*' => 'required|integer|min:1|distinct', 'evidence' => 'required|string|min:5|max:2000'])->validate();
            $actor = User::findOrFail($data['actor']);
            $ids = array_map('intval', $data['documents']);
            $report = $backfill->preview($ids, (int) $data['branch'], $data['evidence'], $actor);
            if ($this->option('execute')) {
                if ($this->option('dry-run') || ! $this->option('confirmed') || strlen((string) $this->option('revision')) !== 64 || mb_strlen(trim((string) $this->option('reason'))) < 3) {
                    $this->error('التنفيذ يحتاج --confirmed و--revision من المعاينة و--reason، دون --dry-run.');

                    return self::INVALID;
                }
                $report['journal_ids'] = $backfill->execute($ids, (int) $data['branch'], $data['evidence'], $this->option('reason'), $this->option('revision'), $actor);
            }
            $report['mode'] = $this->option('execute') ? 'executed' : 'dry-run';
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return collect($report['plans'])->contains('status', 'rejected') ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('لم تُنفذ المعالجة: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
