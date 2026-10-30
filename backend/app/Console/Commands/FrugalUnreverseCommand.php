<?php

namespace App\Console\Commands;

use App\Services\Media\AiSpendGuard;
use App\Services\Media\FrugalUnreverseService;
use Illuminate\Console\Command;

/**
 * David 2026-10-30: the reversed `sleeping_to_idle` (frugal B) »looks like rewinding a tape« —
 * it was dropped. This gives every pet / shared look that already got the reversal a real fal
 * clip through the normal pipeline (FrugalUnreverseService) — the bulk sweep; the normal
 * generation paths also replace legacy slots they meet (QA #456 M1). Never scheduled.
 * `--dry-run` lists the slots and the estimated cost (an upper bound — it can shrink before the
 * real run) and changes nothing (0 $).
 */
class FrugalUnreverseCommand extends Command
{
    protected $signature = 'media:frugal-unreverse
        {--dry-run : List the reversed slots and the estimated cost, queue nothing}
        {--limit= : At most this many items (a pet, or a shared look + stage = one item)}';

    protected $description = 'Replace the reversed sleeping_to_idle clips (dropped frugal B) with real fal clips';

    public function handle(FrugalUnreverseService $service, AiSpendGuard $guard): int
    {
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $plan = $service->plan($limit);

        $summary = sprintf('%d item(s), %d pet slot(s), %d new fal clip(s), estimated $%.2f ($%.2f each). Reversed clips no pet shows any more (deleted by a real run): %d.%s Budget left today: $%.2f of $%.2f, this month: $%.2f of $%.2f.',
            count($plan['items']), $plan['slots'], $plan['generate'], $plan['cost_usd'], $service->clipCostUsd(), $plan['releasable'],
            $plan['more'] > 0 ? " {$plan['more']} more item(s) beyond --limit." : '',
            max(0, $guard->dailyCapUsd() - $guard->spentTodayUsd()), $guard->dailyCapUsd(),
            max(0, $guard->monthlyCapUsd() - $guard->spentThisMonthUsd()), $guard->monthlyCapUsd());

        if ($plan['items'] !== []) {
            $this->table(['Owner', 'Stage', 'Look row', 'Pet slots', 'Pets', 'Action', 'Est. cost'], array_map(fn (array $i) => [
                $i['owner'],
                $i['stage'] ?? '—',
                $i['look_row'] ?? '—',
                implode(', ', $i['slots']),
                implode(', ', $i['pets']),
                $i['action'],
                sprintf('$%.2f', $i['cost_usd']),
            ], $plan['items']));
        } else {
            $this->info('No reversed sleeping_to_idle left to replace.');
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: '.$summary);

            return self::SUCCESS;
        }

        $result = $service->run(null, $limit);

        return match ($result['outcome']) {
            'queued' => tap(self::SUCCESS, fn () => $this->info(sprintf('Queued %d item(s) (%d pet slot(s)), estimated $%.2f — the total was pre-checked against the budget, but each clip is checked again at its submit, so a run can end up only partly queued (a refused clip keeps the reversal and is listed again). The reversed clip stays visible until the new one is stored. Reversed clips released now: %d.', $result['queued'], $result['slots'], $result['cost_usd'], $result['released']))),
            'busy' => tap(self::FAILURE, fn () => $this->error('Another frugal-unreverse run is in progress — try again in a minute.')),
            'budget' => tap(self::FAILURE, fn () => $this->error(sprintf('Estimated $%.2f does not fit the AI budget now — nothing queued (try --limit). %s', $result['cost_usd'], $summary))),
            'ai_off' => tap(self::FAILURE, fn () => $this->error('fal.ai is disabled (FAL_AI_API_KEY) or its balance is flagged as exhausted — nothing queued.')),
            default => tap(self::SUCCESS, fn () => $this->info(sprintf('Nothing to queue. Reversed clips released: %d.', $result['released']))),
        };
    }
}
