<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Department;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Imports the REAL purchasing data (from the developed + old sheets) as demo data:
 *  - vendors.json               → vendors
 *  - purchase_requests.json     → department-linked requisitions (developed sheet)
 *  - purchase_requests_old.json → branch-linked historical rows (old per-branch sheets)
 *  - purchase_orders.json       → POs, linked to requisitions by external number
 */
class PurchasingSeeder extends Seeder
{
    private string $dataDir;

    public function run(): void
    {
        @set_time_limit(600);
        $this->dataDir = database_path('data');

        // Clean slate for the purchasing module.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['purchase_request_updates', 'purchase_orders', 'purchase_quotations', 'purchase_requests', 'vendors'] as $t) {
            DB::table($t)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $vendorByName = $this->seedVendors();
        $branchByName = $this->buildBranchMap();
        $buyerByName = $this->buildBuyerMap();
        $deptByName = $this->buildDeptMap();

        $extToId = $this->seedRequests($vendorByName, $branchByName, $buyerByName, $deptByName);
        $this->seedOrders($extToId, $vendorByName);

        $this->command?->info('Purchasing import done.');
    }

    // ---------------- vendors ----------------
    private function seedVendors(): array
    {
        $rows = $this->json('vendors.json');
        foreach ($rows as $r) {
            if (empty($r['name'])) {
                continue;
            }
            Vendor::create([
                'name' => $r['name'],
                'phone' => $r['phone'] ?? null,
                'address' => $r['address'] ?? null,
                'specialty' => $r['specialty'] ?? null,
                'supply_duration' => $r['supply_duration'] ?? null,
                'rating' => $r['rating'] ?? null,
                'notes' => $r['notes'] ?? null,
                'active' => true,
            ]);
        }

        // Add any extra vendor names seen in the data (old rows + POs) as name-only vendors.
        $extra = [];
        foreach (['purchase_requests_old.json', 'purchase_orders.json'] as $f) {
            foreach ($this->json($f) as $r) {
                $n = trim((string) ($r['vendor_name'] ?? ''));
                if ($n !== '') {
                    $extra[$this->norm($n)] = $n;
                }
            }
        }
        $existing = Vendor::pluck('name')->map(fn ($n) => $this->norm($n))->flip();
        foreach ($extra as $key => $name) {
            if (! isset($existing[$key])) {
                Vendor::create(['name' => $name, 'active' => true]);
            }
        }

        return Vendor::pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [$this->norm($name) => $id])->all();
    }

    // ---------------- requests ----------------
    private function seedRequests(array $vendorByName, array $branchByName, array $buyerByName, array $deptByName): array
    {
        $seq = 0;
        $extToId = [];
        $now = now();

        $insert = function (array $batch) {
            if ($batch) {
                DB::table('purchase_requests')->insert($batch);
            }
        };

        // 1) Developed sheet (department-linked).
        $batch = [];
        foreach ($this->json('purchase_requests.json') as $r) {
            $seq++;
            $req = $this->date($r['requested_at'] ?? null) ?? $now;
            $ref = 'PR-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
            $deptName = $r['requesting_dept_name'] ?? null;
            $row = [
                'reference' => $ref,
                'ext_number' => $r['ext_number'] ?? null,
                'requester_id' => null,
                'requester_name' => $deptName,
                'requesting_dept_id' => $deptName ? ($deptByName[$this->norm($deptName)] ?? null) : null,
                'requesting_dept_name' => $deptName,
                'branch_id' => null,
                'item_name' => $this->cut($r['item_name'] ?? 'صنف', 250),
                'unit' => $r['unit'] ?? null,
                'quantity' => $r['quantity'] ?? null,
                'category' => $r['category'] ?? null,
                'priority' => $r['priority'] ?? 'normal',
                'status' => $r['status'] ?? 'new',
                'buyer_id' => $this->matchBuyer($r['buyer_name'] ?? null, $buyerByName),
                'buyer_name' => $r['buyer_name'] ?? null,
                'unit_price' => null,
                'execution_price' => null,
                'selected_vendor_id' => null,
                'requested_at' => $req,
                'pricing_started_at' => $this->date($r['pricing_started_at'] ?? null),
                'pricing_ended_at' => $this->date($r['pricing_ended_at'] ?? null),
                'execution_started_at' => $this->date($r['execution_started_at'] ?? null),
                'execution_ended_at' => $this->date($r['execution_ended_at'] ?? null),
                'received_qty' => $this->cut($r['received_qty'] ?? null, 250),
                'sla_pricing_days' => 3,
                'sla_execution_days' => 7,
                'notes' => $r['notes'] ?? null,
                'extra_notes' => $r['extra_notes'] ?? null,
                'created_at' => $req,
                'updated_at' => $req,
            ];
            $batch[] = $row;
            if (! empty($r['ext_number'])) {
                $extToId[(string) $r['ext_number']] = $seq; // temp: sequence == id after fresh truncate
            }
            if (count($batch) >= 500) {
                $insert($batch);
                $batch = [];
            }
        }
        $insert($batch);

        // 2) Old per-branch sheet (branch-linked historical).
        $batch = [];
        foreach ($this->json('purchase_requests_old.json') as $r) {
            $seq++;
            $req = $this->date($r['requested_at'] ?? null) ?? $now;
            $ref = 'PR-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
            $branchName = $r['branch_name'] ?? null;
            $vendorName = $r['vendor_name'] ?? null;
            $batch[] = [
                'reference' => $ref,
                'ext_number' => null,
                'requester_id' => null,
                'requester_name' => $r['requester_name'] ?? $branchName,
                'requesting_dept_id' => null,
                'requesting_dept_name' => $branchName,
                'branch_id' => $branchName ? $this->matchBranch($branchName, $branchByName) : null,
                'item_name' => $this->cut($r['item_name'] ?? 'صنف', 250),
                'unit' => $r['unit'] ?? null,
                'quantity' => $r['quantity'] ?? null,
                'category' => $r['category'] ?? null,
                'priority' => 'normal',
                'status' => $r['status'] ?? 'purchased',
                'buyer_id' => $this->matchBuyer($r['buyer_name'] ?? null, $buyerByName),
                'buyer_name' => $r['buyer_name'] ?? null,
                'unit_price' => $r['unit_price'] ?? null,
                'execution_price' => $r['execution_price'] ?? null,
                'selected_vendor_id' => $vendorName ? ($vendorByName[$this->norm($vendorName)] ?? null) : null,
                'requested_at' => $req,
                'pricing_started_at' => null,
                'pricing_ended_at' => null,
                'execution_started_at' => null,
                'execution_ended_at' => $this->date($r['received_at'] ?? null),
                'received_at' => $this->date($r['received_at'] ?? null),
                'received_qty' => null,
                'sla_pricing_days' => 3,
                'sla_execution_days' => 7,
                'notes' => $this->cut(($vendorName ? 'المورد: '.$vendorName.'. ' : '').($r['notes'] ?? ''), 500) ?: null,
                'extra_notes' => null,
                'created_at' => $req,
                'updated_at' => $req,
            ];
            if (count($batch) >= 500) {
                $insert($batch);
                $batch = [];
            }
        }
        $insert($batch);

        return $extToId;
    }

    // ---------------- purchase orders ----------------
    private function seedOrders(array $extToId, array $vendorByName): void
    {
        $batch = [];
        foreach ($this->json('purchase_orders.json') as $r) {
            $ext = isset($r['ext_request_number']) ? (string) $r['ext_request_number'] : null;
            $reqId = ($ext !== null && isset($extToId[$ext])) ? $extToId[$ext] : null;
            $vendorName = $r['vendor_name'] ?? null;
            $issued = $this->date($r['issued_at'] ?? null) ?? now();
            $batch[] = [
                'po_number' => (string) ($r['po_number'] ?? PurchaseOrderNext()),
                'purchase_request_id' => $reqId,
                'ext_request_number' => $ext,
                'vendor_id' => $vendorName ? ($vendorByName[$this->norm($vendorName)] ?? null) : null,
                'vendor_name' => $vendorName,
                'issued_at' => $issued,
                'amount' => $r['amount'] ?? null,
                'expected_delivery_at' => $this->date($r['expected_delivery_at'] ?? null),
                'supply_status' => $r['supply_status'] ?? null,
                'payment_status' => $r['payment_status'] ?? null,
                'payment_terms' => $this->cut($r['payment_terms'] ?? null, 250),
                'created_at' => $issued,
                'updated_at' => $issued,
            ];
            if (count($batch) >= 500) {
                DB::table('purchase_orders')->insert($batch);
                $batch = [];
            }
        }
        if ($batch) {
            DB::table('purchase_orders')->insert($batch);
        }
    }

    // ---------------- helpers ----------------
    private function json(string $file): array
    {
        $path = $this->dataDir.DIRECTORY_SEPARATOR.$file;
        if (! is_file($path)) {
            return [];
        }

        return json_decode(file_get_contents($path), true) ?: [];
    }

    private function date(?string $d): ?Carbon
    {
        if (! $d) {
            return null;
        }
        try {
            return Carbon::parse($d);
        } catch (\Throwable) {
            return null;
        }
    }

    private function norm(?string $s): string
    {
        $s = (string) $s;
        $s = str_replace(['أ', 'إ', 'آ', 'ـ'], ['ا', 'ا', 'ا', ''], $s);

        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    private function cut(?string $s, int $n): ?string
    {
        if ($s === null) {
            return null;
        }
        $s = trim($s);

        return $s === '' ? null : mb_substr($s, 0, $n);
    }

    private function buildBranchMap(): array
    {
        return Branch::pluck('id', 'branch_name')
            ->mapWithKeys(fn ($id, $name) => [$this->norm($name) => $id])->all();
    }

    private function matchBranch(string $name, array $map): ?int
    {
        $key = $this->norm($name);
        if (isset($map[$key])) {
            return $map[$key];
        }
        foreach ($map as $bkey => $id) {
            if ($bkey !== '' && (str_contains($bkey, $key) || str_contains($key, $bkey))) {
                return $id;
            }
        }

        return null;
    }

    private function buildDeptMap(): array
    {
        // Light Arabic → department mapping for common requesting departments.
        $aliases = [
            'الصيانة' => 'maintenance', 'صيانة' => 'maintenance',
            'المبيعات' => 'sales', 'التسويق' => 'marketing', 'المشتريات' => 'purchasing',
            'المالية' => 'finance', 'الموارد البشرية' => 'hr', 'المخزن' => 'warehouse',
            'الشئون الادارية' => 'operation', 'التشغيل' => 'operation',
        ];
        $bySlug = Department::pluck('id', 'slug')->all();
        $out = [];
        foreach ($aliases as $ar => $slug) {
            if (isset($bySlug[$slug])) {
                $out[$this->norm($ar)] = $bySlug[$slug];
            }
        }

        return $out;
    }

    private function buildBuyerMap(): array
    {
        // Purchasing-department users, keyed by normalized name for fuzzy matching.
        $dept = Department::where('slug', 'purchasing')->first();
        if (! $dept) {
            return [];
        }

        return User::where('department_id', $dept->id)->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [$this->norm($name) => $id])->all();
    }

    private function matchBuyer(?string $name, array $map): ?int
    {
        if (! $name) {
            return null;
        }
        $key = $this->norm(str_replace(['أ/', 'أ /', 'ا/'], '', $name));
        foreach ($map as $bkey => $id) {
            if ($bkey !== '' && (str_contains($bkey, $key) || str_contains($key, $bkey))) {
                return $id;
            }
        }

        return null;
    }
}

if (! function_exists('PurchaseOrderNext')) {
    function PurchaseOrderNext(): string
    {
        static $n = 900000;

        return (string) (++$n);
    }
}
