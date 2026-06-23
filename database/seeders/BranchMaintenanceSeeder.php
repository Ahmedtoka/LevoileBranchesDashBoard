<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketUpdate;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class BranchMaintenanceSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/branch_maintenance.json');
        if (! file_exists($path)) {
            $this->command?->warn('branch_maintenance.json not found — skipping.');

            return;
        }

        $rows = json_decode(file_get_contents($path), true) ?: [];
        $branches = Branch::pluck('id', 'branch_name');
        $maintDept = Department::where('slug', 'maintenance')->first();
        if (! $maintDept) {
            return;
        }

        $workers = User::where('department_id', $maintDept->id)
            ->where('is_department_manager', false)->pluck('id')->all();
        $createdBy = User::where('email', 'ops@levoile.test')->value('id')
            ?? User::where('email', 'admin@levoile.test')->value('id');

        $ref = (int) str_replace('TK-', '', (string) Ticket::max('reference')) ?: 0;
        $i = 0;
        $priorities = ['low', 'medium', 'medium', 'high'];

        foreach ($rows as $row) {
            $branchId = $branches[$row['branch']] ?? null;
            if (! $branchId) {
                continue;
            }

            $created = isset($row['date']) && $row['date']
                ? Carbon::parse($row['date'])
                : Carbon::now()->subDays(rand(1, 60));

            $status = $row['status'] ?? 'open';
            // Spread some "open" into assigned to make the demo richer.
            if ($status === 'open' && $i % 4 === 0) {
                $status = 'assigned';
            }

            $assigned = in_array($status, ['assigned', 'in_progress', 'postponed', 'not_fixed', 'closed'], true);
            $workerId = ($assigned && $workers) ? $workers[$i % count($workers)] : null;

            $ref++;
            $ticket = Ticket::create([
                'reference' => 'TK-'.str_pad((string) $ref, 4, '0', STR_PAD_LEFT),
                'title' => $row['category'].' — '.\Illuminate\Support\Str::limit($row['request'], 90),
                'description' => $row['request'].($row['notes'] ? "\nملاحظة: ".$row['notes'] : ''),
                'branch_id' => $branchId,
                'department_id' => $maintDept->id,
                'created_by' => $createdBy,
                'assigned_to' => $workerId,
                'status' => $status,
                'priority' => $priorities[$i % count($priorities)],
                'category' => $row['category'],
                'sla_hours' => 48,
                'due_at' => (clone $created)->addHours(48),
                'assigned_at' => $workerId ? (clone $created)->addHours(rand(2, 20)) : null,
                'scheduled_at' => $workerId ? (clone $created)->addDays(rand(1, 3)) : null,
                'resolved_at' => $status === 'closed' ? (clone $created)->addDays(rand(1, 5)) : null,
                'closed_at' => $status === 'closed' ? (clone $created)->addDays(rand(1, 5)) : null,
                'created_at' => $created,
                'updated_at' => $created,
            ]);

            TicketUpdate::create([
                'ticket_id' => $ticket->id,
                'user_id' => $createdBy,
                'action' => 'created',
                'to_status' => $status,
                'note' => 'مستورد من شيت صيانة الفروع.',
                'created_at' => $created,
                'updated_at' => $created,
            ]);

            $i++;
        }

        $this->command?->info("Imported {$i} maintenance tickets from the branch sheet.");
    }
}
