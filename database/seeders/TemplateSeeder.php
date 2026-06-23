<?php

namespace Database\Seeders;

use App\Models\ChecklistQuestion;
use App\Models\ChecklistSection;
use App\Models\Department;
use App\Models\Role;
use App\Models\VisitTemplate;
use Illuminate\Database\Seeder;

class TemplateSeeder extends Seeder
{
    protected array $dept = [];

    protected array $roles = [];

    /** English question text => Arabic translation. */
    public const AR = [
        // Operation Manager
        'Quick check of last visit actions' => 'مراجعة سريعة لإجراءات الزيارة السابقة',
        'New shipment / windows / mannequins / sections — no display defects' => 'الشحنة الجديدة / الفاترينة / المانيكان / السكاشن — لا توجد عيوب في العرض',
        'Best & slow items checked and action taken' => 'مراجعة الأكثر والأبطأ مبيعًا واتخاذ الإجراء',
        'Pricing — all models have prices' => 'التسعير — كل الموديلات عليها أسعار',
        'IBT — no pending transfers' => 'التحويلات — لا توجد تحويلات معلقة',
        'Toplist check done' => 'تم مراجعة التوب ليست',
        'VM tools needed reviewed' => 'مراجعة احتياجات أدوات العرض',
        'Manpower & staff shortage checked' => 'مراجعة العمالة ونقص الموظفين',
        'Performance appraisal file done & signed' => 'ملف تقييم الأداء معمول وموقّع',
        'Uniform & ID & hygiene' => 'اليونيفورم والـ ID والنظافة الشخصية',
        'Damage check done' => 'تم مراجعة الدامدج',
        'Stock room standard maintained' => 'معايير المخزن مطبّقة',
        'VM tools & marketing materials stored well' => 'أدوات العرض والمواد التسويقية مخزّنة جيدًا',
        'CS training files available' => 'ملفات تدريب خدمة العملاء متاحة',
        'Smiles, greeting & open dialogue' => 'الابتسامة والترحيب والحوار المفتوح',
        'Customer complaints reviewed' => 'تم مراجعة شكاوى العملاء',
        'All required maintenance is done' => 'تم عمل كل الصيانة المطلوبة',
        // Store Manager
        'All staff present per schedule & shift times' => 'جميع الموظفين حاضرون حسب الجدول ومواعيد الشفت',
        'All staff wearing the uniform' => 'جميع الموظفين يرتدون اليونيفورم',
        'All staff wearing ID badge' => 'جميع الموظفين يرتدون بطاقة التعريف',
        'Staff personal hygiene confirmed' => 'تم التأكد من النظافة الشخصية للموظفين',
        'Targets discussed (daily/monthly/KPIs)' => 'تمت مناقشة المستهدف (يومي/شهري/مؤشرات الأداء)',
        'Customer service skills emphasized' => 'التأكيد على مهارات خدمة العملاء',
        'New collection arrivals known' => 'معرفة وصول الكوليكشن الجديد',
        'Team motivated & instructions delivered' => 'تحفيز الفريق وتوصيل التعليمات',
        'Store facade needs cleaning?' => 'هل واجهة المحل تحتاج إلى تنظيف؟',
        'Store sign needs cleaning?' => 'هل لافتة الفرع تحتاج إلى تنظيف؟',
        'Sign & external lighting working' => 'إضاءة اللافتة والإضاءة الخارجية تعمل',
        'External glass polished' => 'تم تلميع الزجاج الخارجي',
        'Floors are clean' => 'الأرضيات نظيفة',
        'Mirrors are clean' => 'المرايات نظيفة',
        'Treasures & stands are clean' => 'التريزرات والاستاندات نظيفة',
        'Lighting fully working & correctly directed' => 'الإضاءة تعمل بالكامل وموجّهة بشكل صحيح',
        'Lighting fully working' => 'الإضاءة تعمل بالكامل',
        'No merchandise left after customers leave' => 'لا توجد بضاعة متروكة بعد خروج العملاء',
        'Cashier area clean & organized all day' => 'منطقة الكاشير نظيفة ومنظمة طوال اليوم',
        'Selling bags stock reviewed & requested' => 'مراجعة مخزون شنط البيع وطلب الاحتياجات',
        'Office tools + cash pad & visa reviewed' => 'مراجعة الأدوات المكتبية والباد كاش والفيزا',
        'Visa machines working & charged' => 'ماكينات الفيزا تعمل ومشحونة',
        'Electricity meter balance checked' => 'مراجعة رصيد عداد الكهرباء',
        'Pending customer returns handled' => 'التعامل مع مرتجعات العملاء المعلقة',
        'Damage separated & sent after approval' => 'فصل الدامدج وإرساله بعد الموافقة',
        'Any non-displayed merchandise / why' => 'أي بضاعة غير معروضة / والسبب',
        'Any defective merchandise on display' => 'أي بضاعة بها عيب ومعروضة',
        'Cotton models rotation done for scarf section' => 'عمل روتيشن لموديلات القطن لقسم الطرح',
        'Display matches last update' => 'العرض مطابق لآخر تحديث',
        'Sold models replenished via Top list, no gaps' => 'تعويض الموديلات المباعة من التوب ليست بدون فراغات',
        'Any models need ironing' => 'أي موديلات تحتاج إلى كي',
        'Price signs inside the branch reviewed' => 'مراجعة لافتات الأسعار داخل الفرع',
        'Mannequins changed per last update' => 'تغيير المانيكان حسب آخر تحديث',
        'Stock room organized, no bags on floor (ROZ)' => 'المخزن منظم ولا توجد أكياس على الأرض (ROZ)',
        'Merchandise stored per display & sales priority' => 'تخزين البضاعة حسب أولوية العرض والبيع',
        'Emails & WhatsApp checked and answered' => 'مراجعة الإيميلات والواتساب والرد عليها',
        'Training files available inside the branch' => 'ملفات التدريب متاحة داخل الفرع',
        'All staff aware of product knowledge' => 'جميع الموظفين على علم بمعلومات المنتج',
        'New hires trained & signed company policy' => 'الموظفون الجدد تم تدريبهم وتوقيعهم على سياسة الشركة',
        'Staff evaluations done & shared' => 'عمل تقييمات الموظفين ومشاركتها',
        'Weekly reports sent on time' => 'إرسال التقارير الأسبوعية في وقتها',
        // VM
        'Clean & well maintained' => 'نظيفة ومصينة جيدًا',
        'Mannequins dressed per window guidelines & well styled' => 'المانيكان ملبّس حسب إرشادات الفاترينة ومنسّق جيدًا',
        'Lighting is working and well positioned' => 'الإضاءة تعمل وفي وضع جيد',
        'Visual materials well used (window decoration)' => 'أدوات العرض مستخدمة جيدًا (ديكور الفاترينة)',
        'Current campaign in windows' => 'الحملة الحالية معروضة في الفاترينة',
        'Garments are steamed' => 'تم كي الملابس',
        'Correct placement as per store map' => 'الاستاندات في أماكنها الصحيحة حسب خريطة الفرع',
        'Wall display sections as per visual guidelines' => 'سكاشن العرض الحائطية حسب القواعد المرسلة',
        'Logical product adjacencies' => 'تقاربات منطقية بين المنتجات',
        'Best selling by section checked & action taken' => 'مراجعة الأكثر مبيعًا لكل قسم واتخاذ الإجراء',
        'Slow movers by section checked & action taken' => 'مراجعة الأبطأ مبيعًا لكل قسم واتخاذ الإجراء',
        'Display areas well maintained and clean' => 'مناطق العرض مصينة ونظيفة',
        'Lighting directed correctly per guidelines' => 'الإضاءة موجّهة بشكل صحيح حسب الإرشادات',
        'In-store communication tools well used' => 'أدوات العرض والأسعار مستخدمة جيدًا داخل الفرع',
        'Accessories on cash counter correct per guideline' => 'الإكسسوار على الكاش بشكل صحيح حسب التوجيهات',
        'Garments ironed & iron in good condition' => 'الملابس مكوية والمكواة بحالة جيدة',
        'Capacity correct level on the table' => 'مستوى الأعداد على الترابيزة صحيح',
        'Capacity correct level on the fixture' => 'مستوى الأعداد على استاند العرض صحيح',
        'Capacity correct level on the wall' => 'مستوى الأعداد على عرض الحائط صحيح',
        'Capacity correct level on accessories (cash counter)' => 'مستوى الأعداد على استاند الإكسسوار صحيح',
        'Hanger types unified per section & sufficient' => 'توحيد نوع الشماعات لكل سكشن والشماعات كافية',
        'Hangers & sale signs stored properly & accessible' => 'الشماعات ولافتات التخفيض مخزّنة جيدًا ويسهل الوصول إليها',
        'Display tools (bars, shelves) stored properly' => 'أدوات العرض (البارات والرفوف) مخزّنة جيدًا',
        'Extra mannequins stored, no outdated decorations' => 'المانيكان الزائد مخزّن بدون ديكورات قديمة',
        // Area Manager
        'The windows are clean and with sufficient depth' => 'الفاترينة نظيفة وبعمق كافٍ',
        'Outfit on mannequin matched & attractive per last update' => 'إطلالة المانيكان متناسقة وجذابة حسب آخر تحديث',
        'New collection items displayed in primary area' => 'معروضات الكوليكشن الجديد في المنطقة الأساسية',
        'Follow the toplist' => 'متابعة التوب ليست',
        'Seamless Kuwaiti available with enough colors' => 'السيملس الكويتي متاح بألوان كافية',
        'Printed chiffon available with enough colors' => 'الشيفون المطبوع متاح بألوان كافية',
        'Combo linen scarf available' => 'طرحة الكتان كومبو متاحة',
        'Customer needs / feedback captured' => 'تسجيل احتياجات وملاحظات العملاء',
        'Music is working' => 'الموسيقى تعمل',
        'Store cleaning & outside sign / basket' => 'نظافة الفرع واللافتة الخارجية / السلة',
        'Air freshener available' => 'معطر الجو متاح',
        'Section handling correct' => 'هاندلينج السكاشن صحيح',
        'Marketing materials in the right position' => 'المواد التسويقية في مكانها الصحيح',
        'All rolls (Visa/Cash/Barcode/Prices) available' => 'كل الرولات (فيزا/كاش/باركود/أسعار) متاحة',
        'All sizes of carrier bags available' => 'كل مقاسات شنط الحمل متاحة',
        'All team wearing uniform & scarf' => 'كل الفريق يرتدي اليونيفورم والإيشارب',
        'Manager follows the weekly schedule' => 'المدير يتبع الجدول الأسبوعي',
        'Manager completed the store checklist' => 'المدير أكمل تشيك ليست الفرع',
        'All products with correct prices & position' => 'كل المنتجات بأسعار وأماكن صحيحة',
        'All products with good steaming' => 'كل المنتجات مكوية جيدًا',
        'Free stands & rails organized' => 'الاستاندات والرّيلات الحرة منظمة',
        'Accessories displayed well on cash counter' => 'الإكسسوار معروض جيدًا على الكاش',
        'QR code & return policy visible to customers' => 'الـ QR وسياسة الاستبدال واضحة للعملاء',
        'All VM tools with good storage' => 'كل أدوات العرض مخزّنة جيدًا',
        'Follow & implement VM guidelines' => 'اتباع وتطبيق إرشادات العرض',
        'Stockroom organized & cleaned' => 'المخزن منظم ونظيف',
        'Damage items checked per brand standards' => 'مراجعة الدامدج حسب معايير البراند',
        'Follow the transfer between stores' => 'متابعة التحويلات بين الفروع',
        'CS training file available & implemented' => 'ملف تدريب خدمة العملاء متاح ومطبّق',
        'Brand induction for the new hire' => 'تعريف البراند للموظف الجديد',
        'Follow new hire training & probation evaluation' => 'متابعة تدريب الموظف الجديد وتقييم فترة الاختبار',
        'Review evaluation with manager & staff' => 'مراجعة التقييم مع المدير والموظفين',
        'Review & follow product knowledge with team' => 'مراجعة ومتابعة معلومات المنتج مع الفريق',
        'Review & follow the 6 steps' => 'مراجعة ومتابعة الـ 6 خطوات',
        'Review & follow ROZ and schedule' => 'مراجعة ومتابعة الـ ROZ والجدول',
    ];

    public function run(): void
    {
        $this->dept = Department::pluck('id', 'slug')->toArray();
        $this->roles = Role::pluck('id', 'slug')->toArray();

        // Only the Store Manager daily checklist is seeded by default.
        // (operationManager/vmChecklist/areaManager remain available to rebuild via the Builder.)
        $this->storeManager();
    }

    /** 01 - Operation Manager */
    protected function operationManager(): void
    {
        $tpl = $this->template('Operation Manager', 'operation-manager', 'operation', 'ops_manager',
            'Operation Manager store visit — product, staff, stock, CS & maintenance.', false);

        $this->sections($tpl, [
            ['Product & Display', 'المنتج والعرض', [
                $this->q('Quick check of last visit actions', 'merchandise', 'medium'),
                $this->q('New shipment / windows / mannequins / sections — no display defects', 'merchandise', 'high'),
                $this->q('Best & slow items checked and action taken', 'merchandise', 'medium'),
                $this->q('Pricing — all models have prices', 'merchandise', 'high'),
                $this->q('IBT — no pending transfers', 'stock_control', 'medium'),
                $this->q('Toplist check done', 'merchandise', 'low'),
                $this->q('VM tools needed reviewed', 'merchandise', 'low'),
            ]],
            ['Headcount & Staff', 'العمالة والموظفين', [
                $this->q('Manpower & staff shortage checked', 'store', 'high', people: true),
                $this->q('Performance appraisal file done & signed', 'store', 'medium', people: true),
                $this->q('Uniform & ID & hygiene', 'store', 'low', people: true),
            ]],
            ['Stock Rooms', 'المخازن', [
                $this->q('Damage check done', 'stock_control', 'medium'),
                $this->q('Stock room standard maintained', 'stock_control', 'medium'),
                $this->q('VM tools & marketing materials stored well', 'merchandise', 'low'),
            ]],
            ['Customer Service', 'خدمة العملاء', [
                $this->q('CS training files available', 'store', 'low', people: true),
                $this->q('Smiles, greeting & open dialogue', 'store', 'medium', people: true),
                $this->q('Customer complaints reviewed', 'operation', 'medium'),
            ]],
            ['Store Maintenance', 'صيانة الفرع', [
                $this->q('All required maintenance is done', 'maintenance', 'high'),
            ]],
        ]);
    }

    /** 02 - Store Manager (daily) */
    protected function storeManager(): void
    {
        $tpl = $this->template('Store Manager', 'store-manager', 'store_manager', 'store_manager',
            'Store Manager daily operational checklist.', false);

        $this->sections($tpl, [
            ['Staff', 'الموظفين', [
                $this->q('All staff present per schedule & shift times', 'store', 'medium', people: true),
                $this->q('All staff wearing the uniform', 'store', 'low', people: true),
                $this->q('All staff wearing ID badge', 'store', 'low', people: true),
                $this->q('Staff personal hygiene confirmed', 'store', 'low', people: true),
            ]],
            ['Team Brief', 'بريف الفريق', [
                $this->q('Targets discussed (daily/monthly/KPIs)', 'store', 'low'),
                $this->q('Customer service skills emphasized', 'store', 'low'),
                $this->q('New collection arrivals known', 'merchandise', 'low'),
                $this->q('Team motivated & instructions delivered', 'store', 'low'),
            ]],
            ['Outside Store', 'خارج المحل', [
                $this->q('Store facade needs cleaning?', 'store', 'medium'),
                $this->q('Store sign needs cleaning?', 'store', 'low'),
                $this->q('Sign & external lighting working', 'maintenance', 'high'),
                $this->q('External glass polished', 'store', 'low'),
            ]],
            ['Inside Store', 'داخل المحل', [
                $this->q('Floors are clean', 'store', 'medium'),
                $this->q('Mirrors are clean', 'store', 'low'),
                $this->q('Treasures & stands are clean', 'store', 'low'),
                $this->q('Lighting fully working & correctly directed', 'maintenance', 'high'),
            ]],
            ['Fitting Room', 'غرفة القياس', [
                $this->q('Floors are clean', 'store', 'low'),
                $this->q('Mirrors are clean', 'store', 'low'),
                $this->q('Lighting fully working', 'maintenance', 'medium'),
                $this->q('No merchandise left after customers leave', 'store', 'low'),
            ]],
            ['Cash Area', 'منطقة الكاشير', [
                $this->q('Cashier area clean & organized all day', 'store', 'low'),
                $this->q('Selling bags stock reviewed & requested', 'purchasing', 'low'),
                $this->q('Office tools + cash pad & visa reviewed', 'operation', 'low'),
                $this->q('Visa machines working & charged', 'it', 'high'),
                $this->q('Electricity meter balance checked', 'maintenance', 'medium'),
                $this->q('Pending customer returns handled', 'operation', 'medium'),
                $this->q('Damage separated & sent after approval', 'stock_control', 'medium'),
            ]],
            ['Display', 'العرض', [
                $this->q('Any non-displayed merchandise / why', 'merchandise', 'medium'),
                $this->q('Any defective merchandise on display', 'merchandise', 'high'),
                $this->q('Cotton models rotation done for scarf section', 'merchandise', 'low'),
                $this->q('Display matches last update', 'merchandise', 'low'),
                $this->q('Sold models replenished via Top list, no gaps', 'merchandise', 'medium'),
                $this->q('Any models need ironing', 'store', 'low'),
                $this->q('Price signs inside the branch reviewed', 'merchandise', 'low'),
                $this->q('Mannequins changed per last update', 'merchandise', 'low'),
            ]],
            ['Stock Room', 'المخزن', [
                $this->q('Stock room organized, no bags on floor (ROZ)', 'stock_control', 'medium'),
                $this->q('Merchandise stored per display & sales priority', 'stock_control', 'low'),
            ]],
            ['Administration', 'الإدارة', [
                $this->q('Emails & WhatsApp checked and answered', 'store', 'low'),
                $this->q('Training files available inside the branch', 'store', 'low', people: true),
                $this->q('All staff aware of product knowledge', 'store', 'low', people: true),
                $this->q('New hires trained & signed company policy', 'store', 'medium', people: true),
                $this->q('Staff evaluations done & shared', 'store', 'medium', people: true),
                $this->q('Weekly reports sent on time', 'operation', 'low'),
            ]],
        ]);
    }

    /** 03 - VM Checklist (scored, 90 pts) */
    protected function vmChecklist(): void
    {
        $tpl = $this->template('VM Checklist', 'vm-checklist', 'vm', 'vm_manager',
            'Visual merchandising audit with weighted scoring (90 pts).', true);

        $sections = [
            ['Window', 'نافذة العرض', 20, [
                ['Clean & well maintained', 'store', 3],
                ['Mannequins dressed per window guidelines & well styled', 'merchandise', 2],
                ['Lighting is working and well positioned', 'maintenance', 1],
                ['Visual materials well used (window decoration)', 'merchandise', 4],
                ['Current campaign in windows', 'merchandise', 5],
                ['Garments are steamed', 'store', 5],
            ]],
            ['In Store', 'داخل الفرع', 40, [
                ['Correct placement as per store map', 'merchandise', 4],
                ['Wall display sections as per visual guidelines', 'merchandise', 4],
                ['Logical product adjacencies', 'merchandise', 4],
                ['Best selling by section checked & action taken', 'merchandise', 4],
                ['Slow movers by section checked & action taken', 'merchandise', 4],
                ['Display areas well maintained and clean', 'store', 4],
                ['Lighting directed correctly per guidelines', 'maintenance', 4],
                ['In-store communication tools well used', 'merchandise', 4],
                ['Accessories on cash counter correct per guideline', 'merchandise', 4],
                ['Garments ironed & iron in good condition', 'store', 4],
            ]],
            ['Capacity', 'الطاقة الاستيعابية', 15, [
                ['Capacity correct level on the table', 'merchandise', 3],
                ['Capacity correct level on the fixture', 'merchandise', 3],
                ['Capacity correct level on the wall', 'merchandise', 3],
                ['Capacity correct level on accessories (cash counter)', 'merchandise', 3],
                ['Hanger types unified per section & sufficient', 'stock_control', 3],
            ]],
            ['Stock Room', 'المخزن', 15, [
                ['Hangers & sale signs stored properly & accessible', 'stock_control', 5],
                ['Display tools (bars, shelves) stored properly', 'stock_control', 5],
                ['Extra mannequins stored, no outdated decorations', 'stock_control', 5],
            ]],
        ];

        foreach ($sections as $sIndex => [$title, $titleAr, $weight, $questions]) {
            $section = ChecklistSection::create([
                'visit_template_id' => $tpl->id, 'title' => $title, 'title_ar' => $titleAr,
                'weight' => $weight, 'sort_order' => $sIndex,
            ]);
            foreach ($questions as $qIndex => [$text, $deptSlug, $score]) {
                ChecklistQuestion::create(array_merge(
                    $this->q($text, $deptSlug, 'medium', score: $score),
                    ['checklist_section_id' => $section->id, 'sort_order' => $qIndex]
                ));
            }
        }
    }

    /** 04 - Area Manager */
    protected function areaManager(): void
    {
        $tpl = $this->template('Area Manager', 'area-manager', 'area_manager', 'area_manager',
            'Area Manager visit — Commercial, Products Feedback, Operations & People.', false);

        $this->sections($tpl, [
            ['Commercial', 'تجاري', [
                $this->q('The windows are clean and with sufficient depth', 'store', 'medium'),
                $this->q('Outfit on mannequin matched & attractive per last update', 'merchandise', 'low'),
                $this->q('New collection items displayed in primary area', 'merchandise', 'medium'),
                $this->q('Follow the toplist', 'merchandise', 'low'),
            ]],
            ['Products Feedback', 'مراجعة المنتجات', [
                // Pass also captures a number (colors in store); fail captures note + photo.
                $this->q('Seamless Kuwaiti available with enough colors', 'merchandise', 'medium',
                    passFields: [['type' => 'number', 'required' => false]]),
                $this->q('Printed chiffon available with enough colors', 'merchandise', 'medium',
                    passFields: [['type' => 'number', 'required' => false]]),
                $this->q('Combo linen scarf available', 'stock_control', 'high'),
                $this->q('Customer needs / feedback captured', 'merchandise', 'low',
                    passFields: [['type' => 'note', 'required' => false]],
                    failFields: [['type' => 'note', 'required' => true]]),
            ]],
            ['Operations', 'العمليات', [
                $this->q('Music is working', 'it', 'low'),
                $this->q('Store cleaning & outside sign / basket', 'store', 'high'),
                $this->q('Air freshener available', 'operation', 'low'),
                $this->q('Section handling correct', 'merchandise', 'medium'),
                $this->q('Marketing materials in the right position', 'merchandise', 'low'),
                $this->q('All rolls (Visa/Cash/Barcode/Prices) available', 'operation', 'medium'),
                $this->q('All sizes of carrier bags available', 'purchasing', 'medium'),
                $this->q('All team wearing uniform & scarf', 'store', 'low', people: true),
                $this->q('Manager follows the weekly schedule', 'operation', 'medium'),
                $this->q('Manager completed the store checklist', 'store', 'medium'),
                $this->q('All products with correct prices & position', 'merchandise', 'high'),
                $this->q('All products with good steaming', 'store', 'medium'),
                $this->q('Free stands & rails organized', 'merchandise', 'low'),
                $this->q('Accessories displayed well on cash counter', 'merchandise', 'low'),
                $this->q('QR code & return policy visible to customers', 'merchandise', 'low'),
                $this->q('All VM tools with good storage', 'merchandise', 'low'),
                $this->q('All required maintenance is done', 'maintenance', 'high'),
                $this->q('Follow & implement VM guidelines', 'merchandise', 'low'),
                $this->q('Stockroom organized & cleaned', 'stock_control', 'medium'),
                $this->q('Damage items checked per brand standards', 'stock_control', 'medium'),
                $this->q('Follow the transfer between stores', 'stock_control', 'low'),
            ]],
            ['People', 'الموظفين', [
                $this->q('CS training file available & implemented', 'store', 'low', people: true),
                $this->q('Brand induction for the new hire', 'store', 'low', people: true),
                $this->q('Follow new hire training & probation evaluation', 'store', 'medium', people: true),
                $this->q('Review evaluation with manager & staff', 'store', 'medium', people: true),
                $this->q('Review & follow product knowledge with team', 'store', 'low', people: true),
                $this->q('Review & follow the 6 steps', 'store', 'low', people: true),
                $this->q('Review & follow ROZ and schedule', 'store', 'low', people: true),
            ]],
        ]);
    }

    // ---------- helpers ----------

    protected function template(string $name, string $slug, string $type, string $roleSlug, string $desc, bool $scored): VisitTemplate
    {
        $tpl = VisitTemplate::updateOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'type' => $type, 'role_id' => $this->roles[$roleSlug] ?? null,
             'description' => $desc, 'scored' => $scored, 'active' => true]
        );
        $tpl->sections()->delete();

        return $tpl;
    }

    /**
     * Build a pass/fail question. Outcome fields default to:
     *   PASS  -> nothing extra
     *   FAIL  -> note (required) + photo (required)
     * Pass override / fail override can be provided.
     */
    protected function q(string $text, string $deptSlug, string $priority = 'medium',
        bool $people = false, ?int $score = null, ?array $passFields = null, ?array $failFields = null,
        ?string $textAr = null): array
    {
        $sla = ['low' => 72, 'medium' => 48, 'high' => 24, 'critical' => 8][$priority] ?? 48;

        $passConfig = $passFields ?? [];
        $failConfig = $failFields ?? [
            ['type' => 'note', 'required' => true],
            ['type' => 'photo', 'required' => true],
        ];

        return [
            'question_text' => $text,
            'question_text_ar' => $textAr ?? (self::AR[$text] ?? null),
            'type' => 'boolean',
            'input_type' => 'boolean',
            'answer_types' => ['boolean'],
            'pass_config' => $passConfig,
            'fail_config' => $failConfig,
            'options' => null,
            'max_score' => $score,
            'responsible_department_id' => $this->dept[$deptSlug] ?? null,
            'responsible_department_ids' => isset($this->dept[$deptSlug]) ? [$this->dept[$deptSlug]] : [],
            'comment_required_on_fail' => true,
            'photo_required_on_fail' => true,
            'auto_create_ticket_on_fail' => true,
            'is_people_issue' => $people,
            'default_priority' => $priority,
            'sla_hours' => $sla,
        ];
    }

    protected function sections(VisitTemplate $tpl, array $sections): void
    {
        foreach ($sections as $sIndex => [$title, $titleAr, $questions]) {
            $section = ChecklistSection::create([
                'visit_template_id' => $tpl->id, 'title' => $title, 'title_ar' => $titleAr,
                'sort_order' => $sIndex,
            ]);
            foreach ($questions as $qIndex => $q) {
                ChecklistQuestion::create(array_merge($q, [
                    'checklist_section_id' => $section->id, 'sort_order' => $qIndex,
                ]));
            }
        }
    }
}
