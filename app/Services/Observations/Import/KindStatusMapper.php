<?php

namespace App\Services\Observations\Import;

/**
 * Record Type + "Status / Rating" of a workbook row -> observation kind, draft status, rating and whether a
 * ticket is created (and closed at once for "Pending Verification"). Spec section 13 rules.
 */
class KindStatusMapper
{
    private const RATINGS = ['جيد' => 'good', 'good' => 'good', 'متوسط' => 'average', 'average' => 'average', 'ضعيف' => 'poor', 'poor' => 'poor'];

    private const DONE = ['done', 'completed', 'training completed', 'closed', 'تم', 'منتهي', 'منتهى'];

    private const PENDING = ['pending verification', 'بانتظار التحقق'];

    /**
     * @param  bool  $hasBranch  false for "All branches" rows: never an issue (spec decision 11)
     * @return array{kind:string, status:string, rating:?string, creates_ticket:bool, ticket_closed:bool, source_done:bool}
     */
    public function map(string $recordType, string $status, ?string $rating = null, bool $hasBranch = true): array
    {
        $type = BranchMapper::normalise($recordType);
        $state = BranchMapper::normalise($status);
        $rating = $rating !== null ? (self::RATINGS[BranchMapper::normalise($rating)] ?? null) : (self::RATINGS[$state] ?? null);

        $done = in_array($state, self::DONE, true);
        $pending = in_array($state, self::PENDING, true);

        $kind = match (true) {
            $type === 'violation', $type === 'issue' => 'issue',
            $type === 'training', $type === 'activity', $type === 'activity / plan', $type === 'plan' => 'activity',
            $type === 'maintenance task' => $done ? 'activity' : 'issue',
            $type === 'assessment' => 'assessment',
            $type === 'positive' => 'positive',
            $type === 'reported finding' => $hasBranch ? 'issue' : 'activity',
            default => $rating !== null ? 'assessment' : ($hasBranch ? 'issue' : 'activity'),
        };

        if (! $hasBranch && in_array($kind, ['issue', 'assessment'], true)) {
            $kind = 'activity';
        }

        [$draftStatus, $ticket, $closed] = match ($kind) {
            'positive' => ['positive', false, false],
            'activity' => ['closed_no_action', false, false],
            'assessment' => $rating === 'poor' ? ['open', true, false] : ['closed_no_action', false, false],
            default => match (true) {
                $pending => ['waiting_verification', true, true],
                $done => ['closed_no_action', false, false],
                default => ['open', true, false],
            },
        };

        return [
            'kind' => $kind,
            'status' => $draftStatus,
            'rating' => $rating,
            'creates_ticket' => $ticket,
            'ticket_closed' => $closed,
            'source_done' => $done,
        ];
    }
}
