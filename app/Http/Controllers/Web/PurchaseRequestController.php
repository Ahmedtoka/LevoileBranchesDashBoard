<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\PurchaseQuotation;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PurchaseService;
use App\Support\DateRange;
use Illuminate\Http\Request;

class PurchaseRequestController extends Controller
{
    public function __construct(protected PurchaseService $service) {}

    // -------- access helpers --------
    private function deptSlug(User $u): ?string
    {
        return $u->is_department_manager || $u->department_id
            ? optional(Department::find($u->department_id))->slug
            : null;
    }

    private function canSeeAll(User $u): bool
    {
        return $u->hasRole(['super_admin', 'ops_manager', 'branch_director'])
            || in_array($this->deptSlug($u), ['purchasing', 'finance'], true);
    }

    private function isPurchasing(User $u): bool
    {
        return $u->hasRole(['super_admin', 'ops_manager']) || $this->deptSlug($u) === 'purchasing';
    }

    private function isFinance(User $u): bool
    {
        return $u->hasRole(['super_admin', 'ops_manager']) || $this->deptSlug($u) === 'finance';
    }

    private function scopeQuery($q, User $u)
    {
        if ($this->canSeeAll($u)) {
            return $q;
        }

        return $q->where(function ($w) use ($u) {
            $w->where('requester_id', $u->id);
            if ($u->department_id) {
                $w->orWhere('requesting_dept_id', $u->department_id);
            }
            if ($u->branch_id) {
                $w->orWhere('branch_id', $u->branch_id);
            }
        });
    }

    // -------- list --------
    public function index(Request $request)
    {
        $user = $request->user();
        $range = DateRange::fromRequest($request, 'month');
        $q = $request->query('q');

        $base = fn () => $this->scopeQuery(
            PurchaseRequest::query()->whereBetween('requested_at', [$range->from, $range->to]),
            $user
        );

        $query = $base()->with(['branch', 'requestingDepartment', 'buyer', 'selectedVendor',
            'batchOrders:id,po_number', 'orders:id,purchase_request_id,po_number']);

        foreach (['status', 'priority', 'category'] as $f) {
            if ($request->filled($f)) {
                $query->where($f, $request->query($f));
            }
        }
        if ($request->filled('buyer_id')) {
            $query->where('buyer_id', $request->query('buyer_id'));
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->query('branch_id'));
        }
        if ($request->filled('open')) {
            $query->whereIn('status', PurchaseRequest::OPEN_STATUSES);
        }
        if ($q) {
            $query->where(fn ($w) => $w->where('item_name', 'like', "%{$q}%")
                ->orWhere('reference', 'like', "%{$q}%")
                ->orWhere('ext_number', 'like', "%{$q}%"));
        }

        $stats = [
            'total' => $base()->count(),
            'open' => $base()->whereIn('status', PurchaseRequest::OPEN_STATUSES)->count(),
            'pricing' => $base()->where('status', 'pricing')->count(),
            'finance' => $base()->where('status', 'finance_review')->count(),
            'executing' => $base()->whereIn('status', ['po_issued', 'executing'])->count(),
            'purchased' => $base()->where('status', 'purchased')->count(),
        ];

        $requests = $query->orderByDesc('requested_at')->paginate(25)->withQueryString();
        $categories = PurchaseRequest::query()->select('category')->whereNotNull('category')->distinct()->pluck('category');
        $buyers = User::whereHas('department', fn ($d) => $d->where('slug', 'purchasing'))->orderBy('name')->get();
        $branches = Branch::orderBy('branch_name')->get();
        $vendors = Vendor::where('active', true)->orderBy('name')->get();

        $can = ['purchasing' => $this->isPurchasing($user), 'finance' => $this->isFinance($user)];

        return view('dashboard.purchasing.index', compact('requests', 'stats', 'range', 'categories', 'buyers', 'branches', 'vendors', 'can'));
    }

    // -------- bulk (batch) actions --------
    public function bulk(Request $request)
    {
        $data = $request->validate([
            'action' => ['required', 'in:assign_buyer,send_finance,finance_approve,issue_po,cancel'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:purchase_requests,id'],
            'buyer_id' => ['nullable', 'exists:users,id'],
            'vendor_id' => ['nullable', 'exists:vendors,id'],
            'amount' => ['nullable', 'numeric'],
            'payment_status' => ['nullable', 'in:cash,cheque,credit'],
            'note' => ['nullable', 'string'],
        ]);
        $user = $request->user();
        $ids = $data['ids'];
        $reqs = PurchaseRequest::whereIn('id', $ids)->get();

        switch ($data['action']) {
            case 'assign_buyer':
                $this->authorizePurchasing($request);
                if (empty($data['buyer_id'])) {
                    return back()->with('status', 'اختر القائم بالشراء أولًا.');
                }
                $buyer = User::findOrFail($data['buyer_id']);
                foreach ($reqs as $pr) {
                    $this->service->assignBuyer($pr, $buyer, $user);
                }
                break;

            case 'send_finance':
                $this->authorizePurchasing($request);
                foreach ($reqs as $pr) {
                    $pr->update(['status' => 'finance_review', 'pricing_ended_at' => $pr->pricing_ended_at ?? now()]);
                    $this->service->log($pr, 'finance', null, 'finance_review', 'إرسال مجمّع للمالية', $user);
                }
                break;

            case 'finance_approve':
                abort_unless($this->isFinance($user), 403);
                foreach ($reqs as $pr) {
                    $this->service->financeApprove($pr, $user, $data['note'] ?? null);
                }
                break;

            case 'issue_po':
                $this->authorizePurchasing($request);
                $po = $this->service->issueCombinedPo($ids, $data, $user);

                return back()->with('status', 'تم إصدار أمر توريد مجمّع رقم '.$po->po_number.' لعدد '.count($ids).' طلب.');

            case 'cancel':
                foreach ($reqs as $pr) {
                    abort_unless($this->isPurchasing($user) || $pr->requester_id === $user->id, 403);
                    $this->service->setStatus($pr, 'cancelled', $user, $data['note'] ?? null);
                }
                break;
        }

        return back()->with('status', 'تم تنفيذ الإجراء المجمّع على '.count($ids).' طلب.');
    }

    // -------- detail --------
    public function show(Request $request, PurchaseRequest $pr)
    {
        $user = $request->user();
        abort_unless($this->canSeeAll($user) || $pr->requester_id === $user->id
            || ($user->department_id && $pr->requesting_dept_id === $user->department_id)
            || ($user->branch_id && $pr->branch_id === $user->branch_id), 403);

        $pr->load(['branch', 'requestingDepartment', 'requester', 'buyer', 'financeApprover',
            'selectedVendor', 'quotations.vendor', 'orders.vendor', 'batchOrders.vendor',
            'batchOrders.requests:id,reference,item_name', 'updates.user']);

        $vendors = Vendor::where('active', true)->orderBy('name')->get();
        $buyers = User::whereHas('department', fn ($d) => $d->where('slug', 'purchasing'))->orderBy('name')->get();
        $can = [
            'purchasing' => $this->isPurchasing($user),
            'finance' => $this->isFinance($user),
            'requester' => $pr->requester_id === $user->id,
        ];

        return view('dashboard.purchasing.show', compact('pr', 'vendors', 'buyers', 'can'));
    }

    // -------- create --------
    public function store(Request $request)
    {
        $data = $request->validate([
            'item_name' => ['required', 'string', 'max:250'],
            'unit' => ['nullable', 'string', 'max:50'],
            'quantity' => ['nullable', 'numeric'],
            'category' => ['nullable', 'string', 'max:100'],
            'priority' => ['required', 'in:'.implode(',', PurchaseRequest::PRIORITIES)],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'notes' => ['nullable', 'string'],
        ]);
        $user = $request->user();

        $pr = PurchaseRequest::create([
            'reference' => PurchaseRequest::nextReference(),
            'requester_id' => $user->id,
            'requester_name' => $user->name,
            'requesting_dept_id' => $user->department_id,
            'requesting_dept_name' => optional($user->department)->name,
            'branch_id' => $data['branch_id'] ?? $user->branch_id,
            'item_name' => $data['item_name'],
            'unit' => $data['unit'] ?? null,
            'quantity' => $data['quantity'] ?? null,
            'category' => $data['category'] ?? null,
            'priority' => $data['priority'],
            'status' => 'new',
            'requested_at' => now(),
            'sla_pricing_days' => 3,
            'sla_execution_days' => 7,
            'notes' => $data['notes'] ?? null,
        ]);
        $this->service->log($pr, 'created', null, 'new', 'إنشاء طلب شراء', $user);

        return redirect()->route('purchasing.show', $pr)->with('status', 'تم إنشاء طلب الشراء.');
    }

    // -------- lifecycle actions --------
    public function assignBuyer(Request $request, PurchaseRequest $pr)
    {
        $this->authorizePurchasing($request);
        $data = $request->validate(['buyer_id' => ['required', 'exists:users,id']]);
        $this->service->assignBuyer($pr, User::findOrFail($data['buyer_id']), $request->user());

        return back()->with('status', 'تم تعيين القائم بالشراء وبدء التسعير.');
    }

    public function addQuotation(Request $request, PurchaseRequest $pr)
    {
        $this->authorizePurchasing($request);
        $data = $request->validate([
            'vendor_id' => ['nullable', 'exists:vendors,id'],
            'vendor_name' => ['nullable', 'string', 'max:200'],
            'unit_price' => ['nullable', 'numeric'],
            'total_price' => ['nullable', 'numeric'],
            'supply_duration' => ['nullable', 'string', 'max:100'],
            'payment_terms' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string'],
        ]);
        $this->service->addQuotation($pr, $data, $request->user());

        return back()->with('status', 'تمت إضافة عرض السعر.');
    }

    public function selectQuotation(Request $request, PurchaseRequest $pr)
    {
        $this->authorizePurchasing($request);
        $data = $request->validate(['quotation_id' => ['required', 'exists:purchase_quotations,id']]);
        $q = PurchaseQuotation::where('purchase_request_id', $pr->id)->findOrFail($data['quotation_id']);
        $this->service->selectQuotation($pr, $q, $request->user());

        return back()->with('status', 'تم اعتماد العرض وإرساله للمالية.');
    }

    public function finance(Request $request, PurchaseRequest $pr)
    {
        abort_unless($this->isFinance($request->user()), 403);
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'string']]);
        if ($data['decision'] === 'approve') {
            $this->service->financeApprove($pr, $request->user(), $data['note'] ?? null);

            return back()->with('status', 'تمت موافقة المالية.');
        }
        $this->service->financeReject($pr, $request->user(), $data['note'] ?? null);

        return back()->with('status', 'تم رفض العرض وإعادته للتسعير.');
    }

    public function issuePo(Request $request, PurchaseRequest $pr)
    {
        $this->authorizePurchasing($request);
        $data = $request->validate([
            'po_number' => ['nullable', 'string', 'max:50'],
            'vendor_id' => ['nullable', 'exists:vendors,id'],
            'amount' => ['nullable', 'numeric'],
            'expected_delivery_at' => ['nullable', 'date'],
            'payment_status' => ['nullable', 'in:cash,cheque,credit'],
            'payment_terms' => ['nullable', 'string', 'max:200'],
        ]);
        $this->service->issuePo($pr, $data, $request->user());

        return back()->with('status', 'تم إصدار أمر التوريد.');
    }

    public function execute(Request $request, PurchaseRequest $pr)
    {
        $this->authorizePurchasing($request);
        $this->service->startExecution($pr, $request->user());

        return back()->with('status', 'تم بدء التنفيذ.');
    }

    public function receive(Request $request, PurchaseRequest $pr)
    {
        $this->authorizePurchasing($request);
        $data = $request->validate([
            'received_qty' => ['nullable', 'string', 'max:200'],
            'received_at' => ['nullable', 'date'],
            'supply_status' => ['nullable', 'in:full,partial,over,none'],
        ]);
        $this->service->markReceived($pr, $data, $request->user());

        return back()->with('status', 'تم تسجيل الاستلام والشراء.');
    }

    public function status(Request $request, PurchaseRequest $pr)
    {
        $data = $request->validate(['status' => ['required', 'in:cancelled,rejected,postponed,sufficed'], 'note' => ['nullable', 'string']]);
        $user = $request->user();
        abort_unless($this->isPurchasing($user) || $pr->requester_id === $user->id, 403);
        $this->service->setStatus($pr, $data['status'], $user, $data['note'] ?? null);

        return back()->with('status', 'تم تحديث حالة الطلب.');
    }

    private function authorizePurchasing(Request $request): void
    {
        abort_unless($this->isPurchasing($request->user()), 403, 'إجراءات المشتريات متاحة لإدارة المشتريات فقط.');
    }
}
