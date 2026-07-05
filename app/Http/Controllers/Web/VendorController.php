<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use Illuminate\Http\Request;

class VendorController extends Controller
{
    public function index(Request $request)
    {
        $q = $request->query('q');
        $vendors = Vendor::query()
            ->when($q, fn ($query) => $query->where('name', 'like', "%{$q}%")->orWhere('specialty', 'like', "%{$q}%"))
            ->withCount(['quotations', 'orders'])
            ->orderBy('name')
            ->paginate(30)->withQueryString();

        return view('dashboard.purchasing.vendors', compact('vendors'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        Vendor::create($data + ['active' => true]);

        return back()->with('status', 'تمت إضافة المورد.');
    }

    public function update(Request $request, Vendor $vendor)
    {
        $data = $this->validated($request);
        $vendor->update($data + ['active' => $request->boolean('active')]);

        return back()->with('status', 'تم تحديث المورد.');
    }

    public function destroy(Vendor $vendor)
    {
        if ($vendor->orders()->exists() || $vendor->quotations()->exists()) {
            return back()->with('status', 'لا يمكن حذف المورد لوجود عروض/أوامر مرتبطة. اجعله غير نشط بدلًا من ذلك.');
        }
        $vendor->delete();

        return back()->with('status', 'تم حذف المورد.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:250'],
            'specialty' => ['nullable', 'string', 'max:200'],
            'supply_duration' => ['nullable', 'string', 'max:100'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'notes' => ['nullable', 'string'],
        ]);
    }
}
