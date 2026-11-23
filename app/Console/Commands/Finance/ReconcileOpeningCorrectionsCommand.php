<?php

namespace App\Console\Commands\Finance;

use App\Actions\Finance\ReconcileOpeningCorrections;
use App\Actions\Inventory\OpeningStock\OpeningFinancialSupport;
use App\Models\OpeningStockEntry;
use App\Models\User;
use App\Support\Sales\OperationContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ReconcileOpeningCorrectionsCommand extends Command
{
    protected $signature = 'app:opening-corrections-reconcile {--actor=} {--branch=} {--document=} {--change=} {--classification=} {--date=} {--evidence=} {--revenue-journal=} {--reason=} {--operation=} {--revision=} {--execute} {--confirmed}';

    protected $description = 'Read-only inspection by default; reconcile one documented historical change without changing stock or original journals.';

    public function handle(ReconcileOpeningCorrections $action): int
    {
        try {
            $identity = Validator::make(['actor' => $this->option('actor'), 'branch' => $this->option('branch'), 'document' => $this->option('document')],
                ['actor' => 'required|integer|min:1', 'branch' => 'required|integer|min:1', 'document' => 'required|integer|min:1'])->validate();
            $actor = User::findOrFail($identity['actor']);
            OperationContext::branch($actor, (int) $identity['branch'], 'finance.transition_review');
            abort_unless($actor->is_active && $actor->can('sales.view_cost') && $actor->can('inventory.valuation_review'), 403);
            $document = OpeningStockEntry::where('branch_id', $identity['branch'])->findOrFail($identity['document']);
            if (! $this->option('change')) {
                abort_if($this->option('execute'), 422, 'حدد التغيير والمعالجة والدليل ثم راجع المعاينة أولًا.');
                $report = app(OpeningFinancialSupport::class)->review($document, false, true);
            } else {
                $data = ['change_id' => $this->option('change'), 'classification' => $this->option('classification'),
                    'posting_date' => $this->option('date'), 'evidence' => $this->option('evidence')];
                if ($this->option('revenue-journal')) {
                    $data['revenue_journal_id'] = (int) $this->option('revenue-journal');
                }
                $report = $action->preview($document, $data, $actor);
                if ($this->option('execute')) {
                    $journal = $action->execute($document, $data + ['revision' => $this->option('revision'), 'reason' => $this->option('reason'),
                        'operation_uuid' => $this->option('operation'), 'confirmed' => (bool) $this->option('confirmed')], $actor);
                    $report['result_journal_id'] = $journal->id;
                }
            }
            $report['mode'] = $this->option('execute') ? 'executed' : 'read_only';
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->error(implode(' ', $exception->validator->errors()->all()));
        } catch (HttpExceptionInterface $exception) {
            $this->error($exception->getMessage() ?: 'لا يسمح بتنفيذ هذه العملية.');
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('توقفت المعالجة دون تسوية جزئية؛ راجع السجل الفني قبل إعادة المحاولة.');
        }

        return self::FAILURE;
    }
}
