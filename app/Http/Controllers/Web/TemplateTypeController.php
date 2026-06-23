<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\TemplateType;
use App\Models\VisitTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TemplateTypeController extends Controller
{
    public function index()
    {
        $types = TemplateType::orderBy('name')->get();

        return view('dashboard.types.index', compact('types'));
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $slug = Str::slug($data['name'], '_');

        TemplateType::updateOrCreate(['slug' => $slug], ['name' => $data['name']]);

        return back()->with('status', 'Type added.');
    }

    public function destroy(TemplateType $type)
    {
        if (VisitTemplate::where('type', $type->slug)->exists()) {
            return back()->with('status', 'Cannot delete: templates are using this type.');
        }

        $type->delete();

        return back()->with('status', 'Type deleted.');
    }
}
