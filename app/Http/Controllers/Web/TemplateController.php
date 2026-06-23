<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ChecklistQuestion;
use App\Models\ChecklistSection;
use App\Models\Department;
use App\Models\TemplateType;
use App\Models\Ticket;
use App\Models\VisitTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TemplateController extends Controller
{
    public function index(Request $request)
    {
        $q = $request->query('q');

        $templates = VisitTemplate::withCount(['sections', 'questions'])
            ->when($q, fn ($query) => $query->where('name', 'like', "%{$q}%"))
            ->orderBy('name')
            ->get();

        $types = TemplateType::orderBy('name')->get();

        $summary = [
            'templates' => VisitTemplate::count(),
            'types' => $types->count(),
            'sections' => \App\Models\ChecklistSection::count(),
            'questions' => \App\Models\ChecklistQuestion::count(),
        ];

        return view('dashboard.templates.index', compact('templates', 'types', 'summary'));
    }

    public function store(Request $request)
    {
        $data = $this->validateTemplate($request);
        $data['slug'] = $this->uniqueSlug($data['name']);
        $data['scored'] = $request->boolean('scored');

        $template = VisitTemplate::create($data);

        return redirect()->route('templates.edit', $template)->with('status', 'Template created. Now add sections & questions.');
    }

    public function edit(VisitTemplate $template)
    {
        $template->load('sections.questions.responsibleDepartment');
        $types = TemplateType::orderBy('name')->get();
        $departments = Department::orderBy('name')->get();

        return view('dashboard.templates.builder', compact('template', 'types', 'departments'));
    }

    public function update(Request $request, VisitTemplate $template)
    {
        $data = $this->validateTemplate($request);
        $data['scored'] = $request->boolean('scored');
        $data['active'] = $request->boolean('active');
        $template->update($data);

        return back()->with('status', 'Template updated.');
    }

    public function destroy(VisitTemplate $template)
    {
        $template->delete();

        return redirect()->route('templates.index')->with('status', 'Template deleted.');
    }

    // ---------- Sections ----------

    public function storeSection(Request $request, VisitTemplate $template)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'title_ar' => ['nullable', 'string', 'max:255'],
            'weight' => ['nullable', 'integer', 'min:0'],
        ]);
        $data['sort_order'] = (int) $template->sections()->max('sort_order') + 1;

        $template->sections()->create($data);

        return back()->with('status', 'Section added.');
    }

    public function updateSection(Request $request, ChecklistSection $section)
    {
        $section->update($request->validate([
            'title' => ['required', 'string', 'max:255'],
            'title_ar' => ['nullable', 'string', 'max:255'],
            'weight' => ['nullable', 'integer', 'min:0'],
        ]));

        return back()->with('status', 'Section updated.');
    }

    public function destroySection(ChecklistSection $section)
    {
        $section->delete();

        return back()->with('status', 'Section deleted.');
    }

    // ---------- Questions ----------

    public function storeQuestion(Request $request, ChecklistSection $section)
    {
        $data = $this->validateQuestion($request);
        $data['sort_order'] = (int) $section->questions()->max('sort_order') + 1;

        $section->questions()->create($data);

        return back()->with('status', 'Question added.');
    }

    public function updateQuestion(Request $request, ChecklistQuestion $question)
    {
        $question->update($this->validateQuestion($request));

        return back()->with('status', 'Question updated.');
    }

    public function destroyQuestion(ChecklistQuestion $question)
    {
        $question->delete();

        return back()->with('status', 'Question deleted.');
    }

    // ---------- helpers ----------

    protected function validateTemplate(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'exists:template_types,slug'],
            'description' => ['nullable', 'string'],
        ]);
    }

    protected function validateQuestion(Request $request): array
    {
        $fieldTypes = 'note,number,percentage,photo,video';

        $data = $request->validate([
            'question_text' => ['required', 'string'],
            'question_text_ar' => ['nullable', 'string'],
            'input_type' => ['required', 'in:boolean,options'],
            'options_style' => ['nullable', 'in:buttons,dropdown'],
            'responsible_department_ids' => ['nullable', 'array'],
            'responsible_department_ids.*' => ['exists:departments,id'],
            'default_priority' => ['required', 'in:'.implode(',', Ticket::PRIORITIES)],
            'sla_hours' => ['nullable', 'integer', 'min:1'],
            'max_score' => ['nullable', 'integer', 'min:0'],
            'pass_config' => ['nullable', 'array'],
            'fail_config' => ['nullable', 'array'],
            'options' => ['nullable', 'array'],
        ]);

        $data['type'] = 'boolean';
        $data['answer_types'] = ['boolean'];
        $data['options_style'] = $data['options_style'] ?? 'buttons';

        // Multi-department routing; keep the single column = first for back-compat.
        $deptIds = array_values(array_unique(array_map('intval', $data['responsible_department_ids'] ?? [])));
        $data['responsible_department_ids'] = $deptIds;
        $data['responsible_department_id'] = $deptIds[0] ?? null;

        $data['pass_config'] = $this->normalizeConfig($request->input('pass_config', []));
        $data['fail_config'] = $this->normalizeConfig($request->input('fail_config', []));
        $data['options'] = $data['input_type'] === 'options'
            ? $this->normalizeOptions($request->input('options', []))
            : null;

        $failReq = collect($data['fail_config']);
        $data['comment_required_on_fail'] = (bool) ($failReq->firstWhere('type', 'note')['required'] ?? false);
        $data['photo_required_on_fail'] = (bool) ($failReq->firstWhere('type', 'photo')['required'] ?? false);
        $data['auto_create_ticket_on_fail'] = $request->boolean('auto_create_ticket_on_fail');
        $data['is_people_issue'] = $request->boolean('is_people_issue');

        return $data;
    }

    /** Normalize posted options into rich option objects. */
    protected function normalizeOptions(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['label'])) {
                continue;
            }
            $out[] = [
                'label' => $row['label'],
                'label_ar' => $row['label_ar'] ?? null,
                'fields' => $this->normalizeConfig($row['fields'] ?? []),
                'creates_ticket' => (string) ($row['creates_ticket'] ?? '0') === '1',
                'priority' => in_array($row['priority'] ?? '', Ticket::PRIORITIES, true) ? $row['priority'] : 'medium',
                'department_ids' => array_values(array_unique(array_map('intval', $row['department_ids'] ?? []))),
            ];
        }

        return $out;
    }

    /** Normalize a posted outcome config into [['type'=>..,'required'=>bool], ...]. */
    protected function normalizeConfig(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['type'])) {
                continue;
            }
            $out[] = [
                'type' => $row['type'],
                'required' => (string) ($row['required'] ?? '0') === '1',
            ];
        }

        return $out;
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 1;
        while (VisitTemplate::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
