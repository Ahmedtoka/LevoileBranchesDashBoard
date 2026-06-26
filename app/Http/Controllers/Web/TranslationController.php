<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Translation;
use Illuminate\Http\Request;

class TranslationController extends Controller
{
    /** Central strings page — every term grouped, editable in AR / EN. */
    public function index(Request $request)
    {
        $q = $request->query('q');

        $groups = Translation::query()
            ->when($q, fn ($x) => $x->where('key', 'like', "%{$q}%")
                ->orWhere('ar', 'like', "%{$q}%")->orWhere('en', 'like', "%{$q}%"))
            ->orderBy('group')->orderBy('key')
            ->get()
            ->groupBy('group');

        return view('dashboard.translations.index', compact('groups', 'q'));
    }

    /** Bulk save edits. */
    public function update(Request $request)
    {
        $items = $request->input('t', []); // [id => ['ar'=>, 'en'=>]]
        foreach ($items as $id => $vals) {
            Translation::where('id', $id)->update([
                'ar' => $vals['ar'] ?? null,
                'en' => $vals['en'] ?? null,
            ]);
        }

        // Add a brand-new string if provided.
        if ($request->filled('new_key')) {
            Translation::updateOrCreate(
                ['key' => $request->new_key],
                ['group' => $request->input('new_group', 'common'), 'ar' => $request->new_ar, 'en' => $request->new_en]
            );
        }

        return back()->with('status', 'تم حفظ النصوص.');
    }
}
