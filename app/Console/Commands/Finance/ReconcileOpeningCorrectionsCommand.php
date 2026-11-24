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
    protected $signature = 'app:opening-corrections-reconcile {--actor=} {--branch=} {--document=} {--all} {--change=} {--classification=} {--date=} {--evidence=} {--revenue-journal=} {--reason=} {--operation=} {--revision=} {--execute} {--confirmed}';

    protected $description = 'Read-only inspection by default; scan historical opening evidence or reconcile one explicitly reviewed change.';

    public function handle(ReconcileOpeningCorrections $action): int
    {
        try {
            $identity = Validator::make(['actor' => $this->option('actor'), 'branch' => $this->option('branch'), 'document' => $this->option('document')],
                ['actor' => 'required|integer|min:1', 'branch' => 'required|integer|min:1', 'document' => 'nullable|integer|min:1'])->validate();
            abort_if(! $this->option('all') && ! ($identity['document'] ?? null), 422, 'حدد مستندًا أو استخدم --all للمعاينة الشاملة.');
            abort_if($this->option('all') && ($identity['document'] ?? null), 422, 'استخدم --all أو --document، وليس كليهما.');
            abort_if($this->option('all') && ($this->option('execute') || $this->option('change')), 422, 'المعاينة الشاملة للقراءة فقط؛ راجع كل تغيير ثم نفّذه منفردًا بعد موافقة صريحة.');
            $actor = User::findOrFail($identity['actor']);
            OperationContext::branch($actor, (int) $identity['branch'], 'finance.transition_review');
            abort_unless($actor->is_active && $actor->can('sales.view_cost') && $actor->can('inventory.valuation_review'), 403);
            if ($this->option('all')) {
                $documents = OpeningStockEntry::where('branch_id', $identity['branch'])->orderBy('id')->get();
                $reports = $documents->map(fn (OpeningStockEntry $entry): array => $this->scan($entry))->all();
                $report = ['branch_id' => (int) $identity['branch'], 'actor_id' => $actor->id, 'documents' => $reports,
                    'summary' => collect($reports)->countBy('status')->all(), 'stock_changed' => false, 'journal_changed' => false];
                $report['mode'] = 'read_only';
                $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
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

    /** @return array<string, mixed> */
    private function scan(OpeningStockEntry $document): array
    {
        try {
            $review = app(OpeningFinancialSupport::class)->review($document, false, true);
            $changes = collect($review['changes'])->map(fn (array $change): array => [
                'id' => $change['id'], 'status' => $change['status'], 'delta_minor' => $change['delta_minor'],
                'quantity_delta' => $change['quantity_delta'],
            ])->all();
            $pending = collect($review['changes'])->where('status', 'missing')->pluck('id')->all();

            return ['id' => $document->id, 'number' => $document->number,
                'status' => $pending === [] ? 'ready' : 'requires_human_review',
                'reason' => $pending === [] ? 'الأثر المالي موثق أو لا يوجد أثر مالي.' : 'التغييرات المذكورة تحتاج تصنيفًا ودليلًا قبل إنشاء أي قيد.',
                'changes' => $changes, 'pending_change_ids' => $pending,
                'original_journal_id' => $review['original_journal_id'], 'transition_audit_id' => $review['transition_audit_id']];
        } catch (ValidationException $exception) {
            return ['id' => $document->id, 'number' => $document->number, 'status' => 'blocked',
                'reason' => implode(' ', $exception->validator->errors()->all()), 'changes' => [], 'pending_change_ids' => []];
        }
    }
}
