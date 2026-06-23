<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\MaintenanceItem;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class MaintenanceController extends Controller
{
    public function __construct(protected TicketService $tickets) {}

    /** GET /api/maintenance/items */
    public function items(): JsonResponse
    {
        $items = MaintenanceItem::where('active', true)->orderBy('sort_order')
            ->get(['id', 'label', 'label_ar', 'sub_categories']);

        return response()->json(['data' => $items]);
    }

    /** GET /api/maintenance/branches */
    public function branches(): JsonResponse
    {
        $branches = Branch::where('active', true)->orderBy('branch_name')->get(['id', 'branch_name']);

        return response()->json(['data' => $branches]);
    }

    /** GET /api/branch/overview — open visits + open tickets for the user's branch. */
    public function branchOverview(Request $request): JsonResponse
    {
        $branchId = $request->user()->branch_id;
        $branch = $branchId ? Branch::find($branchId) : null;

        if (! $branch) {
            return response()->json(['branch' => null, 'open_visits' => [], 'open_tickets' => []]);
        }

        $openVisits = \App\Models\Visit::with('template')
            ->where('branch_id', $branch->id)
            ->whereIn('status', ['assigned', 'checked_in', 'in_progress'])
            ->latest('scheduled_date')->get()
            ->map(fn ($v) => [
                'id' => $v->id, 'code' => $v->code,
                'template' => optional($v->template)->name,
                'status' => $v->status,
                'date' => optional($v->scheduled_date)->toDateString(),
            ]);

        $openTickets = Ticket::with('department')
            ->where('branch_id', $branch->id)
            ->whereNotIn('status', ['closed'])
            ->latest()->get()
            ->map(fn ($t) => [
                'id' => $t->id, 'reference' => $t->reference, 'title' => $t->title,
                'status' => $t->status, 'priority' => $t->priority,
                'department' => optional($t->department)->name,
                'group_code' => $t->group_code,
            ]);

        return response()->json([
            'branch' => ['id' => $branch->id, 'name' => $branch->branch_name],
            'open_visits' => $openVisits,
            'open_tickets' => $openTickets,
        ]);
    }

    /** POST /api/maintenance/upload — generic image/video upload, returns {path, kind}. */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/3gpp', 'max:51200'],
        ]);
        $file = $request->file('file');
        $kind = str_starts_with((string) $file->getMimeType(), 'video') ? 'video' : 'photo';
        $path = $file->store('evidence/maintenance', 'public');

        return response()->json(['path' => $path, 'kind' => $kind, 'url' => asset('storage/'.$path)]);
    }

    /** POST /api/maintenance/requests — one ticket per item to the Maintenance dept. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.label' => ['required', 'string'],
            'items.*.note' => ['nullable', 'string'],
            'items.*.evidence' => ['nullable', 'array'],
            'items.*.evidence.*.path' => ['required', 'string'],
            'items.*.evidence.*.kind' => ['nullable', 'in:photo,video'],
        ]);

        $deptId = optional(Department::where('slug', 'maintenance')->first())->id;
        $groupCode = 'MR-'.str_pad((string) ((Ticket::max('id') ?? 0) + 1), 5, '0', STR_PAD_LEFT);
        $userId = $request->user()->id;

        $created = [];
        foreach ($data['items'] as $item) {
            $ticket = $this->tickets->createManual([
                'group_code' => $groupCode,
                'title' => 'Maintenance — '.$item['label'],
                'description' => $item['note'] ?? null,
                'branch_id' => $data['branch_id'],
                'department_id' => $deptId,
                'created_by' => $userId,
                'priority' => 'medium',
                'sla_hours' => 48,
                'due_at' => Carbon::now()->addHours(48),
                'category' => Str::slug($item['label']),
            ], $userId);

            foreach ($item['evidence'] ?? [] as $e) {
                $this->tickets->addEvidence($ticket, $e['path'], $e['kind'] ?? 'photo', $request->user());
            }

            $created[] = ['reference' => $ticket->reference, 'item' => $item['label']];
        }

        return response()->json([
            'message' => 'Maintenance request submitted to the Maintenance department.',
            'group_code' => $groupCode,
            'count' => count($created),
            'tickets' => $created,
        ]);
    }
}
