<?php

namespace App\Console\Commands;

use App\Models\Observation;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Console\Command;

class ObservationsEscalate extends Command
{
    protected $signature = 'observations:escalate {--dry-run : List without notifying}';

    protected $description = 'Notify the area manager and ops managers about open observations whose ticket is overdue';

    public function handle(TicketService $tickets): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $count = 0;
        $opsManagerIds = null;

        Observation::query()
            ->overdueOpen()
            ->where(function ($q) {
                $q->whereNull('last_overdue_notified_at')
                    ->orWhere('last_overdue_notified_at', '<', now()->subHours(24));
            })
            ->with(['ticket', 'branch'])
            ->chunkById(100, function ($observations) use ($tickets, $dryRun, &$count, &$opsManagerIds) {
                foreach ($observations as $observation) {
                    $ticket = $observation->ticket;
                    if (! $ticket || ! $ticket->isOverdue()) {
                        continue;
                    }

                    $opsManagerIds ??= User::query()
                        ->where('active', true)
                        ->whereHas('role', fn ($r) => $r->where('slug', 'ops_manager'))
                        ->pluck('id')
                        ->all();

                    // area() the relation, never ->area (legacy text column).
                    $areaManagerId = $observation->branch?->area()->value('manager_id');
                    $recipients = array_values(array_unique(array_filter(
                        array_merge([$areaManagerId], $opsManagerIds)
                    )));

                    $hours = (int) abs($ticket->due_at->diffInHours(now()));
                    $branchName = $observation->branch?->branch_name;

                    $this->line("{$observation->reference} | {$ticket->reference} | {$branchName} | {$hours}h overdue");

                    if (! $dryRun) {
                        foreach ($recipients as $userId) {
                            $tickets->notify(
                                (int) $userId,
                                'observation_overdue',
                                dt('ملاحظة متأخرة', 'Overdue observation'),
                                "{$observation->reference} — {$observation->title} — {$branchName}",
                                $ticket->id,
                            );
                        }
                        $observation->forceFill(['last_overdue_notified_at' => now()])->save();
                    }

                    $count++;
                }
            });

        $this->info("Escalated {$count} overdue observation(s).");

        return self::SUCCESS;
    }
}
