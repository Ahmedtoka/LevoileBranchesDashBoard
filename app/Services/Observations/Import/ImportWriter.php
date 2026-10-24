<?php

namespace App\Services\Observations\Import;

use App\Models\Observation;
use App\Models\ObservationPhoto;
use App\Models\ObservationVisit;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Observations\ObservationService;
use App\Services\TicketService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Writes one visit group through ObservationService (createVisit -> upsertObservations -> submitVisit with
 * `silent` and `due_from`), so the same rules apply as live entry. Rows already imported (same
 * import_source + import_row) are skipped. A failure removes the group's draft visit again.
 */
class ImportWriter
{
    public const IMPORT_EMAIL = 'import@levoile.local';

    public const PLACEHOLDER_AFTER = 'import/placeholder-after.png';

    public const CLOSE_NOTE = 'مستورد: تم الإجراء، بانتظار التحقق';

    public function __construct(private ObservationService $observations, private TicketService $tickets) {}

    /** The system user that owns imported visits: super_admin, inactive (cannot log in). */
    public static function ensureImportUser(): User
    {
        $existing = User::where('email', self::IMPORT_EMAIL)->first();
        if ($existing) {
            return $existing;
        }

        $role = Role::firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin']);

        return User::create([
            'name' => 'Import',
            'email' => self::IMPORT_EMAIL,
            'password' => Str::random(40),
            'role_id' => $role->id,
            'active' => false,
        ]);
    }

    /**
     * @param  array  $group  a VisitGrouper group
     * @return array{visits:int, observations:int, tickets:int, skipped:int, escalated:int}
     */
    public function write(array $group, User $importUser, string $importSource): array
    {
        $result = ['visits' => 0, 'observations' => 0, 'tickets' => 0, 'skipped' => 0, 'escalated' => 0];

        $rows = [];
        foreach ($group['rows'] as $row) {
            if (Observation::where('import_source', $importSource)->where('import_row', (string) $row['row'])->exists()) {
                $result['skipped']++;

                continue;
            }
            $rows[] = $row;
        }

        if ($rows === []) {
            return $result;
        }

        $visit = $this->observations->createVisit($importUser, [
            'type' => $group['type'],
            'department_id' => $group['department_id'],
            'branch_id' => $group['branch_id'],
            'visit_date' => $group['visit_date'],
            'general_notes' => $group['general_notes'],
            'source' => 'import',
        ]);
        $result['visits'] = 1;

        try {
            $saved = $this->observations->upsertObservations($visit, array_map(fn ($row) => $this->item($row), $rows), $importUser);

            foreach ($saved->values() as $i => $observation) {
                $this->fixUp($observation, $rows[$i], $importSource);
            }
            $result['observations'] = $saved->count();

            $dueFrom = $group['type'] === 'field'
                ? Carbon::parse($group['visit_date'])->startOfDay()
                : Carbon::parse($rows[0]['week_end'])->startOfDay();

            $submitted = $this->observations->submitVisit($visit, $importUser, ['silent' => true, 'due_from' => $dueFrom]);
            $result['tickets'] = count($submitted['created_tickets']);

            $this->closePendingVerification($saved, $rows, $importUser);

            $result['escalated'] = Observation::whereIn('id', $saved->pluck('id'))->whereNotNull('escalated_from_risk_level_id')->count();

            $timestamps = array_filter(['started_at' => $group['started_at'] ?? null, 'submitted_at' => $group['submitted_at'] ?? null]);
            if ($timestamps !== []) {
                $visit->update($timestamps);
            }
        } catch (\Throwable $e) {
            $this->discard($visit);

            throw $e;
        }

        return $result;
    }

    private function item(array $row): array
    {
        return [
            'catalog_item_id' => $row['catalog_item_id'],
            'category_id' => $row['category_id'],
            'kind' => $row['kind'],
            'title' => $row['title'],
            'description' => $row['description'],
            'product_or_area' => $row['product_or_area'],
            'extra_values' => $row['extra_values'],
            'rating' => $row['rating'],
            'responsible_department_id' => $row['responsible_department_id'],
            'cc_department_ids' => $row['cc_department_ids'] ?: null,
        ];
    }

    /**
     * The service derives kind / status / event_date from the catalog and the visit; the import knows better
     * for its rows (a positive VM note is not an issue, an undated row has no event date, a closed finding
     * needs no ticket), so those columns are set from the mapped row before submit.
     */
    private function fixUp(Observation $observation, array $row, string $importSource): void
    {
        $attributes = [
            'import_source' => $importSource,
            'import_row' => (string) $row['row'],
            'event_date' => $row['event_date']?->toDateString(),
            'kind' => $row['kind'],
            'rating' => $row['rating'],
            // Pending-verification rows are submitted open (ticket created) and moved after the close.
            'status' => $row['status'] === 'waiting_verification' ? 'open' : $row['status'],
            'classification_status' => $observation->catalog_item_id ? 'catalog' : 'manual',
        ];

        if (in_array($row['kind'], ['positive', 'activity'], true)) {
            $attributes['risk_level_id'] = null;
            $attributes['escalated_from_risk_level_id'] = null;
        }

        $observation->update($attributes);
    }

    /**
     * "Pending Verification": the ticket is closed at once with a placeholder after photo and the observation
     * waits for field verification. The status is set before the close so the ticket-close sync (which would
     * auto-verify a medium/low risk) leaves the row alone.
     */
    private function closePendingVerification($saved, array $rows, User $importUser): void
    {
        foreach ($saved->values() as $i => $observation) {
            if (! ($rows[$i]['ticket_closed'] ?? false)) {
                continue;
            }

            $observation->refresh();
            $ticket = $observation->ticket_id ? Ticket::find($observation->ticket_id) : null;
            if ($ticket === null) {
                continue;
            }

            $observation->update(['status' => 'waiting_verification']);
            $this->ensurePlaceholder();
            $this->tickets->addEvidence($ticket, self::PLACEHOLDER_AFTER, 'after', $importUser);
            $this->tickets->changeStatus($ticket, 'closed', $importUser, self::CLOSE_NOTE);
            Observation::whereKey($observation->id)->update(['status' => 'waiting_verification']);
        }
    }

    /** A 1x1 grey PNG on the public disk so the ticket's after evidence resolves to a real file. */
    private function ensurePlaceholder(): void
    {
        try {
            $disk = Storage::disk('public');
            if (! $disk->exists(self::PLACEHOLDER_AFTER)) {
                $disk->put(self::PLACEHOLDER_AFTER, base64_decode(
                    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGNoaGgAAAMEAYHL3PxEAAAAAElFTkSuQmCC'
                ));
            }
        } catch (\Throwable) {
            // Storage problems never fail the import; the evidence row still carries the path.
        }
    }

    /** Remove a draft visit the import created and could not complete (its observations have no tickets yet). */
    private function discard(ObservationVisit $visit): void
    {
        try {
            $fresh = ObservationVisit::find($visit->id);
            if ($fresh === null || $fresh->status !== 'draft') {
                return;
            }
            $ids = Observation::where('observation_visit_id', $fresh->id)->pluck('id');
            ObservationPhoto::whereIn('observation_id', $ids)->delete();
            Observation::whereIn('id', $ids)->delete();
            $fresh->delete();
        } catch (\Throwable) {
            // Leave the draft for manual cleanup; the original exception is what the caller sees.
        }
    }
}
