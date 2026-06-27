<?php

namespace Database\Seeders;

use App\Models\ChecklistQuestion;
use App\Models\ChecklistSection;
use App\Models\Department;
use App\Models\Role;
use App\Models\VisitTemplate;
use Illuminate\Database\Seeder;

/**
 * Imports the FINAL real checklists from the uploaded sheets:
 *   - Store Manager daily checklist  (database/data/checklist_store_manager.json)
 *   - Area Manager visit checklist   (database/data/checklist_area_manager.json)
 *
 * Each question is pass/fail with a required note + photo on fail, and routes a
 * ticket to every responsible department listed (so a 2-department question
 * opens 2 tickets). "Store" / "Area" are the visitor roles, not ticket targets.
 */
class RealChecklistSeeder extends Seeder
{
    public function run(): void
    {
        $depts = Department::pluck('id', 'slug');
        $roles = Role::pluck('id', 'slug');

        foreach (['checklist_store_manager.json', 'checklist_area_manager.json'] as $file) {
            $path = database_path('data/'.$file);
            if (! file_exists($path)) {
                $this->command?->warn("Missing checklist data: {$file}");
                continue;
            }

            $data = json_decode(file_get_contents($path), true);

            $tpl = VisitTemplate::updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'name' => $data['name'],
                    'type' => $data['type'],
                    'role_id' => $roles[$data['role']] ?? null,
                    'description' => 'الشيك ليست النهائية المستوردة من الشيت.',
                    'scored' => false,
                    'active' => true,
                ]
            );

            // wipe existing sections + questions for a clean re-import
            $sectionIds = $tpl->sections()->pluck('id');
            ChecklistQuestion::whereIn('checklist_section_id', $sectionIds)->delete();
            $tpl->sections()->delete();

            foreach ($data['sections'] as $si => $sec) {
                $section = ChecklistSection::create([
                    'visit_template_id' => $tpl->id,
                    'title' => $sec['title'],
                    'title_ar' => $sec['title_ar'] ?? $sec['title'],
                    'sort_order' => $si,
                ]);

                foreach ($sec['questions'] as $qi => $q) {
                    $ids = [];
                    foreach (($q['depts'] ?? []) as $slug) {
                        if (isset($depts[$slug])) {
                            $ids[] = (int) $depts[$slug];
                        }
                    }

                    // HR-linked or explicitly flagged questions are "people issues":
                    // on fail they list the branch's own employees to pick who it concerns.
                    $isPeople = in_array('hr', $q['depts'] ?? [], true) || ($q['people'] ?? false) === true;

                    ChecklistQuestion::create([
                        'checklist_section_id' => $section->id,
                        'sort_order' => $qi,
                        'question_text' => $q['text'],
                        'question_text_ar' => $q['text_ar'] ?? null,
                        'type' => 'boolean',
                        'input_type' => 'boolean',
                        'answer_types' => ['boolean'],
                        'pass_config' => [],
                        'fail_config' => [
                            ['type' => 'note', 'required' => true],
                            ['type' => 'photo', 'required' => true],
                        ],
                        'options' => null,
                        'max_score' => null,
                        'responsible_department_id' => $ids[0] ?? null,
                        'responsible_department_ids' => $ids,
                        'comment_required_on_fail' => true,
                        'photo_required_on_fail' => true,
                        'auto_create_ticket_on_fail' => true,
                        'is_people_issue' => $isPeople,
                        'default_priority' => 'medium',
                        'sla_hours' => 48,
                    ]);
                }
            }

            $this->command?->info("Imported {$data['name']}: ".count($data['sections']).' sections.');
        }
    }
}
