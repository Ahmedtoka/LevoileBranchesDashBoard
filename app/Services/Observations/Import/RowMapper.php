<?php

namespace App\Services\Observations\Import;

use Carbon\Carbon;

/**
 * One Central Issues row (as WorkbookReader returns it) -> a normalised import record: ids, kind/status,
 * dates, catalog match and the texts the writer stores. Throws UnknownBranch / UnknownDepartment.
 */
class RowMapper
{
    public const SOURCE_RESPONSIBLE = [
        'operations / loss prevention' => ['operation', ['lp']],
        'store operations' => ['operation', []],
        'operations' => ['operation', []],
        'operation' => ['operation', []],
        'maintenance' => ['maintenance', []],
        'finance / loss prevention' => ['operation', ['lp']],
        'loss prevention' => ['lp', []],
        'it' => ['it', []],
        'hr' => ['hr', []],
    ];

    public function __construct(
        private BranchMapper $branches,
        private DepartmentMapper $departments,
        private CatalogMapper $catalog,
        private KindStatusMapper $kinds,
        private DateMapper $dates,
    ) {}

    public static function fromDatabase(array $extraBranches = []): self
    {
        return new self(
            BranchMapper::fromDatabase($extraBranches),
            DepartmentMapper::fromDatabase(),
            CatalogMapper::fromDatabase(),
            new KindStatusMapper,
            new DateMapper,
        );
    }

    /**
     * @param  array  $raw  a WorkbookReader row of `Central Issues`
     * @param  array|null  $original  the matching `Original Observations` row (Merchandising rows), for enrichment
     */
    public function map(array $raw, ?array $original = null): array
    {
        $departmentSlug = $this->departments->toSlug($raw['Department'] ?? '');
        $departmentId = $this->departments->toDepartmentId($raw['Department'] ?? '');

        $branch = $this->branches->toBranchId($raw['Branch'] ?? '');
        $allBranches = $branch === BranchMapper::ALL;
        $branchId = $allBranches ? null : (int) $branch;

        $weekStart = Carbon::parse($raw['Period Start'])->toDateString();
        $weekEnd = Carbon::parse($raw['Period End'])->toDateString();
        $eventDate = $this->dates->eventDate($raw['Event Date (Verified)'] ?? null, $raw['Date Raw'] ?? null, $weekStart, $weekEnd);
        $rawParsed = trim((string) ($raw['Event Date (Verified)'] ?? '')) === '' ? $this->dates->parseRaw($raw['Date Raw'] ?? null) : null;
        $rawDropped = $eventDate === null ? $rawParsed : null;

        $kind = $this->kinds->map($raw['Record Type'] ?? '', $raw['Status / Rating'] ?? '', null, ! $allBranches);

        $finding = trim((string) ($raw['Finding / Activity'] ?? ''));
        $category = trim((string) ($raw['Category'] ?? ''));
        $allowItems = in_array($kind['kind'], ['issue', 'activity'], true) || $kind['rating'] === 'poor';
        $match = $this->catalog->match($departmentId, $category, $finding, $allowItems);

        [$responsibleId, $ccIds] = $this->responsible($raw['Responsible Department'] ?? '');

        $extra = null;
        if (BranchMapper::normalise($category) === 'مخالفات تتعلق بالتأخير عن ميعاد فتح الفرع') {
            $minutes = $this->minutesLate($finding);
            $extra = $minutes !== null ? ['minutes_late' => $minutes] : null;
        }

        $title = $finding !== '' ? $finding : ($category !== '' ? $category : 'سجل مستورد');

        return [
            'row' => (int) $raw['row'],
            'id' => trim((string) ($raw['ID'] ?? '')),
            'source_file' => trim((string) ($raw['Source File'] ?? '')),
            'source_row' => trim((string) ($raw['Source Row'] ?? '')),
            'department_id' => $departmentId,
            'department_slug' => $departmentSlug,
            'branch_id' => $branchId,
            'all_branches' => $allBranches,
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'event_date' => $eventDate,
            'raw_date' => trim((string) ($raw['Date Raw'] ?? '')),
            'raw_date_dropped' => $rawDropped,
            'kind' => $kind['kind'],
            'status' => $kind['status'],
            'rating' => $kind['rating'],
            'creates_ticket' => $kind['creates_ticket'],
            'ticket_closed' => $kind['ticket_closed'],
            'category_id' => $match['category_id'],
            'catalog_item_id' => $match['catalog_item_id'],
            'matched' => $match['matched'],
            'title' => mb_substr($title, 0, 120),
            'description' => $this->description($raw, $kind, $finding, $allBranches, $rawDropped, $weekStart, $weekEnd, $original),
            'product_or_area' => $this->productOrArea($raw, $original),
            'extra_values' => $extra,
            'responsible_department_id' => $responsibleId,
            'cc_department_ids' => $ccIds,
            'reported_by' => trim((string) ($raw['Reported By'] ?? '')),
            'category' => $category,
            'finding' => $finding,
        ];
    }

    /**
     * An `Original Visits` row -> ['visit_id', 'department_id', 'branch_id', 'date', 'started_at', 'submitted_at', 'notes'].
     */
    public function mapVisit(array $raw): array
    {
        $date = Carbon::parse($raw['Visit Date'])->toDateString();
        $time = function (?string $value) use ($date): ?Carbon {
            $value = trim((string) $value);

            return preg_match('/^\d{1,2}:\d{2}/', $value) ? Carbon::parse($date.' '.$value) : null;
        };

        $people = array_filter([
            trim((string) ($raw['Merchandiser'] ?? '')) !== '' ? 'الميرشندايزر: '.trim($raw['Merchandiser']) : null,
            trim((string) ($raw['Branch Responsible'] ?? '')) !== '' ? 'مسؤول الفرع: '.trim($raw['Branch Responsible']) : null,
        ]);
        $notes = trim((string) ($raw['Overall / Notes'] ?? ''));

        return [
            'visit_id' => trim((string) ($raw['Visit ID'] ?? '')),
            'department_id' => $this->departments->toDepartmentId($raw['Department'] ?? 'Merchandising'),
            'branch_id' => (int) $this->branches->toBranchId($raw['Branch'] ?? ''),
            'date' => $date,
            'started_at' => $time($raw['Start'] ?? null),
            'submitted_at' => $time($raw['End'] ?? null),
            'notes' => trim(implode("\n", array_filter([$notes !== '' ? $notes : null, ...$people]))) ?: null,
        ];
    }

    /** @return array{0:?int, 1:array<int>} responsible department id (fallback for free rows) and cc ids */
    private function responsible(string $source): array
    {
        [$slug, $cc] = self::SOURCE_RESPONSIBLE[BranchMapper::normalise($source)] ?? ['operation', []];

        $id = $this->departmentIdBySlug($slug);
        $ccIds = array_values(array_filter(array_map(fn ($s) => $this->departmentIdBySlug($s), $cc)));

        return [$id, $ccIds];
    }

    private function departmentIdBySlug(string $slug): ?int
    {
        return $this->departments->idForSlug($slug);
    }

    /** "(20 دقيقة)", "32دقيقه", "ساعة", "ساعتين", "ساعة ونصف" -> minutes. */
    private function minutesLate(string $finding): ?int
    {
        if (preg_match('/(\d+)\s*دقيق/u', $finding, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/(\d+)\s*(min|minute)/iu', $finding, $m)) {
            return (int) $m[1];
        }
        if (str_contains($finding, 'ساعتين')) {
            return 120;
        }
        if (preg_match('/ساع[ةه]\s*و\s*نص/u', $finding)) {
            return 90;
        }
        if (preg_match('/(\d+)\s*ساع/u', $finding, $m)) {
            return (int) $m[1] * 60;
        }
        if (preg_match('/ساع[ةه]/u', $finding)) {
            return 60;
        }

        return null;
    }

    private function productOrArea(array $raw, ?array $original): ?string
    {
        $value = trim((string) ($original['Product / Area'] ?? ''));
        if ($value === '' && ($raw['Source File'] ?? '') === 'Existing Merchandising database') {
            $value = trim((string) ($raw['Notes'] ?? ''));
        }

        return $value === '' ? null : mb_substr($value, 0, 191);
    }

    private function description(array $raw, array $kind, string $finding, bool $allBranches, ?Carbon $rawDropped, string $weekStart, string $weekEnd, ?array $original): string
    {
        $lines = [$finding !== '' ? $finding : trim((string) ($raw['Category'] ?? ''))];

        $action = trim((string) ($raw['Corrective Action'] ?? ''));
        if ($action !== '' && $action !== '-') {
            $lines[] = 'الإجراء: '.$action;
        }

        if ($original !== null) {
            $before = trim((string) ($original['Before'] ?? ''));
            $after = trim((string) ($original['Target / After'] ?? ''));
            if ($before !== '' || $after !== '') {
                $lines[] = trim(($before !== '' ? 'قبل: '.$before : '').($before !== '' && $after !== '' ? ' — ' : '').($after !== '' ? 'الهدف: '.$after : ''));
            }
        }

        $notes = trim((string) ($raw['Notes'] ?? ''));
        if ($notes !== '' && ($raw['Source File'] ?? '') !== 'Existing Merchandising database') {
            $lines[] = 'ملاحظات: '.$notes;
        }

        $reporter = trim((string) ($raw['Reported By'] ?? ''));
        if ($reporter !== '') {
            $lines[] = 'المُبلِّغ: '.$reporter;
        }

        $meta = sprintf('مستورد من "%s" (صف %s) — النوع في المصدر: %s — الحالة في المصدر: %s',
            trim((string) ($raw['Source File'] ?? '')), trim((string) ($raw['Source Row'] ?? '')),
            trim((string) ($raw['Record Type'] ?? '')), trim((string) ($raw['Status / Rating'] ?? '')));
        $lines[] = '—';
        $lines[] = $meta;

        if ($rawDropped !== null) {
            $lines[] = sprintf('التاريخ الخام "%s" (%s) خارج الأسبوع %s – %s؛ تاريخ الحدث غير مؤكد.',
                trim((string) ($raw['Date Raw'] ?? '')), $rawDropped->toDateString(), $weekStart, $weekEnd);
        }

        if ($allBranches) {
            $lines[] = 'سجل عام لكل الفروع (بدون فرع محدد)؛ سُجِّل كنشاط بدون طلب.';
        }

        return implode("\n", $lines);
    }
}
