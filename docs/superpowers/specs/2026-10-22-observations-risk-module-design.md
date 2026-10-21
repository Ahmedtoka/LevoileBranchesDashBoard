# Observations & Risk Module — Design

**Project:** LeVoile Branches (`BranchesDashboard` Laravel 12 + `BranchesMobileApp` Flutter)
**Date:** 2026-10-22
**Status:** Approved by owner on 2026-10-22 (with the laptop-visit addition in §9.3)

---

## 1. الهدف والسبب

كل إدارة في Le Voile (CCTV، مراقبة المخزون، الصيانة، Merchandising، المالية، IT، HR، منع الخسائر، وغيرها) بتزور الفروع وبتسجل ملاحظات. حالياً كل إدارة بتبعت ملف Excel أو Word مختلف، وحد بيجمّعهم يدوياً في ملف HTML محلي (`StoresAi/LeVoile_Control_Center_FINAL_REPAIRED.html`) ما بيتزامنش بين المستخدمين. النتيجة: 376 سجل في أسبوعين بتواريخ ناقصة وأسماء فروع مكتوبة بثلاث طرق، ومفيش متابعة فعلية لمين حل إيه.

السيستم الحالي (LevoileBranches) بيحل نفس المشكلة بس لزيارات الشيك ليست فقط. الموديول ده بيضيف عليه:

1. **ملاحظة حرة** من أي إدارة على أي فرع، من غير شيك ليست، من الموبايل أو الويب.
2. **كتالوج ملاحظات** لكل إدارة: البنود المتكررة بتتختار من قائمة بتصنيف جاهز (فئة، درجة ريسك، إدارة مسؤولة، مهلة).
3. **إيجينت تصنيف**: الموظف يكتب نقطه ويدوس "فحص"، الإيجينت يحدد لكل نقطة بند الكتالوج أو الفئة ودرجة الريسك والإدارة المسؤولة، الموظف يأكد، والأدمن يعتمد ويدمج المتكرر في الكتالوج.
4. **مناطق وصلاحيات**: الفروع بتتجمع في مناطق، كل منطقة ليها Area Manager، والـ Operation Manager شايف الكل. كل واحد يشوف الملاحظات اللي على نطاقه وياخد أكشن.
5. **كنا وبقينا**: الملاحظة ما تتقفلش إلا بعد التحقق الفعلي، مع الاحتفاظ بتاريخها الأصلي والصور قبل وبعد.
6. **داشبورد الإدارات**: كل إدارة زارت كام فرع، طلعت كام ملاحظة من كام زيارة، نسبة الحل في المهلة، الفروع المتكررة.

## 2. الأهداف وغير الأهداف

**أهداف**

- الملاحظة بتتسجل في لحظتها بفرع وتاريخ وصورة مؤكدين. مفيش تواريخ ناقصة بعد الاستيراد الأولي.
- أي ملاحظة بتوصل للإدارة المسؤولة في نفس الدقيقة كتيكت عادي في نفس الفلو الموجود (تعيين، تنفيذ، صورة بعد، موافقة).
- درجة الريسك قرار سياسة شركة قابل للمراجعة: جاية من الكتالوج أو الفئة، والإيجينت بيقترح بس.
- الكتالوج بيكبر من الواقع: الأدمن بيحوّل الملاحظات الحرة المتكررة لبنود.
- الـ 376 سجل الموجودين بيتستوردوا كبداية بدون فقد.

**غير أهداف (المرحلة الأولى)**

- قراءة ملفات Excel/Word تلقائياً بالإيجينت.
- Push notifications (FCM). الإشعارات تفضل in-DB + polling زي الموجود.
- إعادة بناء صلاحيات السيستم القديم كله. الموديول الجديد بيطبق Policies على مساراته هو، ويصلح فجوتين محددتين (اعتماد التيكت، الإغلاق) لأنهم جزء من فلو الملاحظة.
- تعديل شاشات الشيك ليست الموجودة.

**ما يُعد فشلاً حتى لو اشتغل**

- ملاحظة اتسجلت وما وصلتش لحد.
- تيكت اتقفل من غير صورة بعد أو من غير تحقق لو الريسك عالي.
- سيدر مسح داتا على اللايف.
- الإيجينت وقع فالموظف ما قدرش يسجل. (الفحص اختياري دايماً، والتصنيف اليدوي موجود.)
- شاشات بالإنجليزي بس أو بالعربي بس. (كل نص جديد بـ `dt()`/`t()` و`LocaleProvider.tr()`.)

## 3. القيود

- السيستم لايف على Cloudways (`stores.levoilestores.com`). كل migration جديدة additive ومحمية بـ `Schema::hasTable/hasColumn`. السيدرز الجديدة idempotent (`updateOrCreate`) ولا تمسح أي حاجة. ممنوع الاعتماد على `db:seed` الكامل.
- Laravel 12.62، PHP 8.2، MySQL/MariaDB، Bootstrap 5.3 RTL من CDN، Chart.js 4، بدون Vite. Sanctum للـ API. Queue حالياً `sync`.
- Flutter مع dio/provider، الترجمة بـ `LocaleProvider` maps، بدون ARB.
- التيكتات الحالية بتشتغل كويس ومستخدمة؛ الموديول بيبني فوقها مش بيستبدلها.
- `Schema::defaultStringLength(191)`.

## 4. المفاهيم

| المفهوم | التعريف |
| --- | --- |
| **Area (منطقة)** | مجموعة فروع ليها Area Manager واحد. جدول جديد `areas`. الفرع ينتمي لمنطقة واحدة. |
| **Department (إدارة)** | موجودة. الإدارة اللي بتسجل الملاحظة = `reporting_department`، واللي بتحلها = `responsible_department`. ممكن يكونوا نفس الإدارة. يُضاف عمود `departments.is_reporting` (bool) يحدد الإدارات اللي ليها فئات وكتالوج وبتسجل ملاحظات. المستخدمون بدون إدارة (`area_manager`, `ops_manager`, `branch_director`) بيسجلوا تحت إدارة `operation` افتراضياً ويقدروا يختاروا إدارة مُبلِّغة تانية عند فتح الزيارة؛ `vm_manager` تحت `vm`. |
| **Category (فئة)** | تصنيف قصير داخل الإدارة المُبلِّغة (5–8 لكل إدارة). كل فئة ليها ريسك افتراضي وإدارة مسؤولة افتراضية ومهلة. جدول `observation_categories`. |
| **Catalog item (بند كتالوج)** | ملاحظة متكررة معرّفة مسبقاً تحت فئة، بتصنيف كامل وحقول إضافية اختيارية. جدول `observation_catalog_items`. |
| **Risk level (درجة الريسك)** | `critical` / `high` / `medium` / `low` / `none`. جدول `risk_levels` بيحمل التسمية بالعربي والإنجليزي واللون والمهلة الافتراضية بالساعات وهل يتطلب تحقق ميداني. `none` للأنشطة اللي مش مشاكل. |
| **Observation visit (زيارة ملاحظات)** | زيارة حرة: إدارة + فرع + تاريخ + مُسجِّل، بدون شيك ليست. جدول `observation_visits`. ملاحظات الويب اللي مش مرتبطة بزيارة (HR/IT على كل الفروع) بتتسجل بزيارة من نوع `desk`. **قاعدة:** `tickets.branch_id` إجباري في السيستم الحالي ويفضل كده؛ لذلك الملاحظة بدون فرع لا يمكن أن تكون `issue`. في شاشة المكتب: لو المستخدم اختار "كل الفروع" النوع يُجبر على `activity`؛ لو عايز تيكتات يختار فروعاً محددة (multi-select) ويتعمل observation + ticket لكل فرع. |
| **Observation (ملاحظة)** | السجل الأساسي. جدول `observations`. ليه نوع `kind`: `issue` (بيطلع تيكت)، `activity` (تدريب/مهمة/خطة، بدون تيكت)، `assessment` (تقييم جيد/متوسط/ضعيف)، `positive` (نقطة قوة). |
| **Ticket** | موجود. الملاحظة من نوع `issue` بتعمل **تيكت واحد** لإدارة مسؤولة واحدة عبر `TicketService::createManual` بأولوية = درجة الريسك ومهلة = من الريسك. إدارات إضافية ممكن تتحط في `cc_department_ids` (إشعار ورؤية فقط، بدون تيكت)؛ مثال: مخالفة الكاشير تيكتها لـ operation وLP في الـ cc. ده بيخلي الإغلاق قرار واحد واضح. |
| **Verification (تحقق)** | إثبات ميداني إن الملاحظة اتحلت، بيعمله حد من الإدارة المُبلِّغة أو Area Manager في زيارة لاحقة. جدول `observation_verifications`. |
| **AI check (فحص)** | طلب واحد للإيجينت على مجموعة نقط حرة، بيرجّع تصنيف مقترح لكل نقطة. جدول `ai_classifications` بيسجل الاقتراح والقرار النهائي. |

## 5. الأدوار والصلاحيات

الأدوار الموجودة بتتستخدم كما هي. الجديد هو تطبيق Policies على مسارات الموديول.

| الدور (slug) | يقدر |
| --- | --- |
| `super_admin` | كل حاجة. إدارة المناطق، الفئات، الكتالوج، درجات الريسك، شاشة اعتماد التصنيفات لكل الإدارات. |
| `ops_manager` | يشوف كل الملاحظات على كل الفروع، الداشبورد الكامل، يقدر يعيد توجيه ملاحظة لإدارة تانية ويرفع ريسك. لا يعدل الكتالوج. |
| `area_manager` | يشوف ملاحظات فروع منطقته. مصدر الحقيقة = `areas.manager_id`؛ حفظ `/admin/areas` يزامن `branch_user` لنفس المدير عشان الشاشات القديمة تفضل شغالة. يسجل ملاحظات. يتحقق من ملاحظات فروعه. |
| `store_manager` / `branch_manager` | يشوف ملاحظات فرعه فقط. يقدر يرد على التيكت لو هو المسؤول (زي دلوقتي). |
| `department_manager` (`is_department_manager`) | يشوف ملاحظات إدارته المُبلِّغة وكل التيكتات الموجهة لإدارته. يعدل فئات وكتالوج إدارته. يعتمد تصنيفات الملاحظات اللي سجّلتها إدارته. |
| `department_employee` | يسجل ملاحظات لإدارته، يشوف ملاحظاته هو، يتحقق من ملاحظات إدارته لو اتكلف. |
| `branch_director` / `vm_manager` | زي `ops_manager` للقراءة؛ يسجلوا ملاحظات. |

**قرار:** الإدارة المسؤولة عن التيكت وموظفيها ما يشوفوش شاشات الموديول خالص. التيكت بيوصلهم زي أي تيكت.

**إصلاحان في الكود القديم (ضمن الموديول):**

1. **مكان التنفيذ:** داخل `TicketService::changeStatus` عشان يغطي كل المسارات (`approve/reject/send-for-approval`، `PATCH /tickets/{id}/status`، `POST /departments/ticket/{id}/status`، وweb `transition`).
2. **من يقدر يحوّل لـ `closed`:** `created_by`، مدير الفرع (`branches.manager_id` أو `users.branch_id` = فرع التيكت مع دور store_manager/branch_manager)، area manager للفرع، `ops_manager`، `super_admin`، ومدير الإدارة المسؤولة (يحتفظ بالإغلاق المباشر الموجود). غير كده 403. ده يحافظ على اعتماد مدير الفرع من `BranchOverviewScreen`.
3. **شرط الإغلاق:** أي تحويل لـ `closed` يرفض لو مفيش `ticket_updates` بـ `evidence_kind='after'` على التيكت (رسالة واضحة). ينطبق على كل التيكتات.
4. المسارات اللي كانت بتتخطى `canTransition` تمر عليه.

## 6. مخطط البيانات

كل الجداول جديدة إلا المذكور. أسماء الأعمدة نهائية عشان الحزم تشتغل بالتوازي.

### `risk_levels`
| عمود | نوع | ملاحظة |
| --- | --- | --- |
| id | | |
| slug | string unique | `critical`,`high`,`medium`,`low`,`none` |
| name_ar, name_en | string | |
| color | string(20) | hex |
| sort_order | uint | critical=1 |
| default_sla_hours | uint nullable | null لـ none |
| requires_field_verification | bool | default: critical/high = true |
| ticket_priority | string | mapping لـ `tickets.priority`: critical→critical, high→high, medium→medium, low→low, none→null |
| active | bool | |

### `areas`
| عمود | نوع |
| --- | --- |
| id, name, name_ar nullable, slug unique |
| manager_id → users nullOnDelete |
| sort_order, active, timestamps |

### تعديل `branches`
- `area_id` → areas nullOnDelete (nullable). العمود القديم `area` (نص) يفضل كما هو ولا يُستخدم في الموديول.

### تعديل `departments`
- `is_reporting` bool default false. السيدر يضبطها true لـ: cctv, stock_control, finance, merchandise, hr, it, lp, maintenance, operation, vm.

### `observation_categories`
| عمود | نوع | ملاحظة |
| --- | --- | --- |
| id | | |
| department_id → departments | | الإدارة المُبلِّغة |
| slug | string | unique مع department_id |
| name_ar, name_en | | |
| default_kind | enum | issue/activity/assessment/positive |
| default_risk_level_id → risk_levels | nullable | |
| default_responsible_department_id → departments | nullable | |
| default_cc_department_ids | json nullable | إدارات تُبلَّغ بدون تيكت |
| default_sla_hours | uint nullable | يغلب مهلة الريسك لو موجود |
| sort_order, active, timestamps | | |

### `observation_catalog_items`
| عمود | نوع | ملاحظة |
| --- | --- | --- |
| id | | |
| category_id → observation_categories cascade | | |
| code | string unique | مثل `CCTV-OPEN-LATE` |
| label_ar, label_en | | |
| keywords | json | كلمات مساعدة للبحث والإيجينت |
| kind | enum | issue/activity/assessment/positive |
| risk_level_id → risk_levels | nullable | |
| responsible_department_id → departments | nullable | |
| cc_department_ids | json nullable | |
| sla_hours | uint nullable | |
| extra_fields | json | `[{key, label_ar, label_en, type: number|text|employee|select, options?, required}]` |
| escalation_rules | json nullable | انظر §7 |
| usage_count | uint | يتحدّث عند كل استخدام |
| sort_order, active, timestamps | | |

### `observation_visits`
| عمود | نوع | ملاحظة |
| --- | --- | --- |
| id, code unique | | `OV-00001` |
| type | enum | `field` (في الفرع) / `desk` (من المكتب) |
| department_id → departments | | المُبلِّغة |
| branch_id → branches nullable | | null = كل الفروع (desk فقط) |
| user_id → users | | المُسجِّل |
| visit_date | date | |
| started_at, submitted_at | timestamp nullable | |
| checkin_latitude, checkin_longitude, checkin_simulated | | نفس أسماء ومنطق `visits` |
| status | enum | `draft` / `submitted` |
| general_notes | text nullable | |
| source | enum | `mobile` / `web` / `import` |
| timestamps | | |

### `observations`
| عمود | نوع | ملاحظة |
| --- | --- | --- |
| id, reference unique | | `OB-00001` |
| observation_visit_id → observation_visits cascade | | |
| branch_id → branches nullable | | مكرر من الزيارة لسهولة الاستعلام |
| reporting_department_id → departments | | |
| responsible_department_id → departments nullable | | |
| cc_department_ids | json nullable | |
| category_id → observation_categories nullable | | |
| catalog_item_id → observation_catalog_items nullable | | null = حرة |
| kind | enum | issue/activity/assessment/positive |
| risk_level_id → risk_levels nullable | | |
| risk_override_reason | text nullable | لو الموظف أو الأدمن غيّر الدرجة الافتراضية |
| title | string | للحرة = أول 120 حرف |
| description | text | |
| product_or_area | string nullable | |
| extra_values | json nullable | قيم `extra_fields` |
| rating | enum nullable | good/average/poor (لـ assessment) |
| event_date | date | تاريخ الحدث الفعلي (غالباً = visit_date) |
| ticket_id → tickets nullable | | |
| status | enum | `open` / `in_progress` / `waiting_verification` / `verified_closed` / `reopened` / `closed_no_action` / `positive` |
| classification_status | enum | `catalog` (من الكتالوج) / `manual` / `ai_pending_review` / `ai_reviewed` |
| classification_reviewed_by → users nullable, classification_reviewed_at | | |
| repeat_count | uint default 0 | عدد مرات نفس البند/الفئة في نفس الفرع خلال 30 يوم عند التسجيل |
| escalated_from_risk_level_id → risk_levels nullable | | لو اتصعّد تلقائي |
| created_by → users | | |
| import_source, import_row | string nullable | للسجلات المستوردة |
| timestamps | | |

فهارس: (branch_id, status)، (reporting_department_id, created_at)، (responsible_department_id, status)، (catalog_item_id, branch_id, created_at).

### `observation_photos`
| عمود | نوع |
| --- | --- |
| id, observation_id cascade |
| path, kind enum `before`/`after`/`other`, source enum camera/gallery |
| uploaded_by → users, timestamps |

(صورة "بعد" بتتسجل هنا وكمان في `ticket_updates` عشان شاشة التيكت القديمة تشوفها.)

### `observation_verifications`
| عمود | نوع | ملاحظة |
| --- | --- | --- |
| id, observation_id cascade | | |
| verified_by → users | | |
| observation_visit_id → observation_visits nullable | | زيارة التحقق |
| result | enum | `resolved` / `not_resolved` / `partially` |
| notes | text nullable | |
| verified_at | timestamp | |
| timestamps | | |

### `ai_classifications`
| عمود | نوع | ملاحظة |
| --- | --- | --- |
| id | | |
| observation_visit_id → observation_visits nullable | | |
| observation_id → observations nullable | | يتربط بعد الحفظ |
| request_hash | string | |
| input_text | text | |
| suggested | json | `{catalog_item_id, category_id, kind, risk_level_slug, responsible_department_id, confidence, reason}` |
| employee_final | json nullable | اللي الموظف أكده |
| admin_final | json nullable | اللي الأدمن اعتمده |
| model, input_tokens, output_tokens, latency_ms | | |
| status | enum | `ok` / `failed` / `skipped` |
| error | text nullable | |
| timestamps | | |

### تعديل `tickets`
- `source` string nullable: `checklist` / `maintenance` / `observation`. Migration تملأ القديم: `visit_id` موجود → checklist، `group_code` يبدأ بـ MR → maintenance.
- `observation_id` → observations nullable.

التقارير القديمة اللي بتستنتج المصدر من `visit_id` تتحول لتستخدم `source`.

## 7. قواعد الريسك والمهلة والتصعيد

**تحديد الدرجة عند التسجيل، بالترتيب:**

1. بند كتالوج → `risk_level_id` بتاعه.
2. وإلا فئة → `default_risk_level_id`.
3. وإلا اقتراح الإيجينت المؤكد من الموظف.
4. الموظف يقدر يغير الدرجة بسبب إجباري (`risk_override_reason`).

**المهلة (SLA):** `catalog_item.sla_hours` ← `category.default_sla_hours` ← `risk_level.default_sla_hours`. القيم الأولية (قابلة للتعديل من الأدمن): critical 24، high 48، medium 72، low 168، none بلا مهلة.

**التصعيد التلقائي (يتنفذ عند التسجيل وفي أمر يومي):**

- **تكرار:** نفس `catalog_item_id` (أو نفس `category_id` لو حرة) في نفس الفرع ≥ 3 مرات خلال 30 يوم → الدرجة تطلع درجة واحدة، ويتسجل `escalated_from_risk_level_id`، وإشعار للـ Area Manager.
- **تأخر:** تيكت فات `due_at` ولسه مفتوح → إشعار للـ Area Manager وop manager، ويظهر "متأخر" في كل الشاشات.
- **قواعد البند** (`escalation_rules` json، اختياري): مثل `{"field":"minutes_late","gte":30,"risk":"high"}` لبند التأخير عن الفتح.

**التحقق والإغلاق:**

- `issue` بيبدأ `open`. لما التيكت يتعيّن → `in_progress`. لما التيكت يتعمله approve → `waiting_verification`.
- لو `risk_level.requires_field_verification = false` (medium/low افتراضياً): approve التيكت بصورة بعد = تحقق كافٍ، الملاحظة تبقى `verified_closed` فوراً وتتسجل verification تلقائية `result=resolved` باسم المعتمد.
- لو `true` (critical/high): لازم `observation_verifications` بنتيجة `resolved` من مستخدم في الإدارة المُبلِّغة أو area manager → `verified_closed`. نتيجة `not_resolved` → `reopened`، يتعمل تيكت جديد مرتبط بنفس الملاحظة، `repeat_count` +1، والتصعيد يشتغل.
- `closed_no_action`: الأدمن أو ops يقفل ملاحظة بسبب (مكررة، خطأ تسجيل).
- التاريخ الأصلي (`event_date`، `created_at`) ما بيتغيرش أبداً.

**الأنشطة والتقييمات:** `activity` و`positive` بيتسجلوا بحالة `closed_no_action`/`positive` بدون تيكت. `assessment` بتقييم `poor` بيتحول تلقائياً لـ `issue` بدرجة الفئة.

## 8. الإيجينت (فحص التصنيف)

**متى:** الموظف كتب نقط حرة (واحدة أو أكتر) وضغط "فحص". اختياري: لو ما ضغطش، يختار الفئة يدوياً.

**التنفيذ:** `app/Services/Ai/ObservationClassifier.php` خلف interface `ClassifierContract` مع driver `AnthropicClassifier` و`NullClassifier` (للاختبارات ولو المفتاح غايب). يستخدم SDK الرسمي `anthropic-sdk-php`. الموديل من `config('services.anthropic.model')` بقيمة افتراضية `claude-opus-5-5`، المفتاح `ANTHROPIC_API_KEY`. الطلب synchronous (الموظف مستني)، timeout 30 ثانية، structured output بـ JSON schema، effort `low`. تفاصيل الاستدعاء تتبع مرجع claude-api للـ PHP وقت التنفيذ.

**المدخلات للموديل (system):**

- دور الإيجينت وقواعد التصنيف.
- الفئات بتاعة الإدارة المُبلِّغة (id، الاسم، الريسك الافتراضي، الإدارة المسؤولة).
- بنود كتالوج الإدارة (id، label، keywords).
- تعريف درجات الريسك.
- قائمة الإدارات المسؤولة المحتملة (id، الاسم).
- آخر 40 مثال معتمد من `ai_classifications` لنفس الإدارة فيهم `admin_final` (نص → قرار). دي طبقة "التدريب": الإيجينت بيتعلم من قرارات الأدمن.

الـ system prompt بيتبني من الـ DB ويتعمله cache (`cache_control`) لأنه ثابت لكل إدارة لحد ما الكتالوج يتغير.

**المدخلات (user):** قائمة النقط مع الفرع والتاريخ.

**المخرجات لكل نقطة:**
```json
{
  "index": 0,
  "catalog_item_id": 12 | null,
  "category_id": 3,
  "kind": "issue",
  "risk_level": "high",
  "responsible_department_id": 4,
  "confidence": 0.86,
  "reason": "سطر واحد بالعربي"
}
```

**قواعد العرض:** `confidence ≥ 0.75` وفيه `catalog_item_id` → تظهر كـ "قصدك: {بند}". أقل من كده → تظهر كتصنيف مقترح بعلامة "مقترح". الموظف يقدر يعدل أي حاجة. كل اقتراح بيتسجل في `ai_classifications` مع `employee_final`.

**الفشل:** أي خطأ من الـ API → الشاشة تقول "الفحص غير متاح، اختر الفئة يدوياً"، والسجل `status=failed`. مفيش إعادة محاولة تلقائية تعطل الموظف.

**التحليل الأسبوعي (مرحلة لاحقة، خارج نطاق هذا المستند):** ملخص الإدارة.

## 9. الفلو خطوة بخطوة

### 9.1 الإعداد (الأدمن، ويب)
1. ينشئ المناطق ويربط الفروع ويحدد Area Manager لكل منطقة.
2. يراجع درجات الريسك (الأسماء، الألوان، المهل، requires_field_verification).
3. يراجع الفئات والكتالوج المزروعين من الداتا الحالية، يعدل ويضيف ويرتب.
4. يضيف `ANTHROPIC_API_KEY` في `.env`.

### 9.2 زيارة ميدانية (موبايل)
1. موظف الإدارة يفتح "زيارة ملاحظات" → يختار الفرع (أو يتحدد من GPS لو قريب) → check-in بنفس منطق الزيارات.
2. يضيف ملاحظات: زر "من الكتالوج" (بحث في بنود إدارته، يملأ الحقول الإضافية، صورة) أو "كتابة حرة" (نص + صورة، سطر لكل نقطة).
3. يدوس "فحص" على الحرة → شاشة المراجعة: كل نقطة قدامها اقتراح الإيجينت بلون الريسك والإدارة المسؤولة والسبب. "تأكيد الكل" أو تعديل فردي.
4. يرسل الزيارة. السيرفر داخل transaction: يحفظ الملاحظات، يحسب `repeat_count` والتصعيد، يعمل تيكت لكل `issue` عبر `createManual` (source=observation، priority من الريسك، due_at من المهلة، title = label البند أو أول سطر، description = النص + القيم الإضافية، created_by = المُسجِّل)، يرفع صورة قبل، يبعت إشعارات (مدير الإدارة المسؤولة، مدير الفرع، Area Manager لو high/critical).
5. الملاحظات الحرة تتعلّم `ai_pending_review`.

### 9.3 الزيارة من الويب (لاب توب في الفرع أو من المكتب)
شاشة الويب `/observations/new` بتعمل **نفس الزيارة الميدانية كاملة** مش بس التسجيل المكتبي، لأن المُسجِّل ممكن يروح الفرع بلاب توب:
- اختيار النوع: **زيارة ميدانية** (`type=field`، فرع واحد إجباري، check-in يدوي بدون GPS يسجل `checkin_simulated=true`) أو **تسجيل مكتبي** (`type=desk`، فرع واحد أو عدة فروع أو كل الفروع).
- نفس مكونات الموبايل: إضافة من الكتالوج (بحث + حقول إضافية)، كتابة حرة سطر لكل نقطة، رفع صور من الجهاز (ملفات، بدون كاميرا) لكل ملاحظة، زر **فحص** بنفس شاشة المراجعة، ثم إرسال. الزيارة تتحفظ كمسودة تلقائياً كل تعديل عشان ما تضيعش لو القفل اتقفل.
- المسودة اللي اتفتحت من الويب ممكن تتكمل من الموبايل والعكس (نفس `observation_visits`).
- الـ API الداخلي للويب = نفس endpoints §11 (الويب يستدعيها بـ fetch مع session auth عبر `auth:sanctum` stateful) عشان ما يبقاش فيه منطقين.

### 9.4 الحل (الإدارة المسؤولة)
زي دلوقتي بالظبط: التيكت يظهر في بورد الإدارة، يتعيّن، الفني ينفذ، يرفع صورة بعد، يبعت للاعتماد. الاعتماد (المُسجِّل أو area manager أو ops) → الملاحظة `waiting_verification` أو `verified_closed` حسب §7.

### 9.5 التحقق الميداني (موبايل)
1. في الزيارة الجاية لنفس الفرع (أو شاشة "متابعة الفرع" من غير زيارة كاملة)، تظهر قائمة الملاحظات `waiting_verification` على الفرع للإدارة المُبلِّغة والـ Area Manager.
2. قدام كل واحدة: صورة قبل، صورة بعد اللي رفعها الفني، وزرين: "اتحلت" (صورة تحقق اختيارية) أو "لسه" (سبب إجباري).
3. النتيجة تتسجل في `observation_verifications` وتتطبق قواعد §7.

### 9.6 اعتماد التصنيفات (الأدمن ومدير الإدارة، ويب)
قائمة الملاحظات `ai_pending_review`. لكل واحدة: النص، اقتراح الإيجينت، تأكيد الموظف. أزرار: **اعتماد**، **تعديل** (فئة/ريسك/إدارة؛ لو التيكت لسه مفتوح يتحدث priority و department ويتسجل في ticket_updates)، **دمج في بند** (اختيار بند كتالوج → الملاحظة تتربط بيه)، **إضافة كبند جديد** (نموذج مسبق التعبئة يعمل بند جديد ويربط الملاحظة). كل قرار يكتب `admin_final` ويحول الحالة `ai_reviewed`. زر "دمج الكل المشابه": يعرض الملاحظات الحرة اللي الإيجينت اقترحلها نفس البند ويعتمدهم دفعة واحدة.

## 10. الشاشات

### ويب (Blade + Bootstrap 5.3 RTL، نفس layout)

قسم جديد في السايدبار "الملاحظات" يظهر لكل الأدوار ما عدا `department_employee` اللي إدارته `is_reporting = false`.

| مسار | الشاشة | لمين |
| --- | --- | --- |
| `/observations` | قائمة كل الملاحظات. فلاتر: أسبوع/مدى تاريخ (`filterbar`)، إدارة مُبلِّغة، إدارة مسؤولة، منطقة، فرع، فئة، ريسك، حالة، نوع، "خارج الكتالوج"، بحث. أعمدة: المرجع، التاريخ، الفرع، الإدارة، الفئة/البند، الملاحظة، الريسك، المسؤولة، الحالة، التيكت، متأخر؟. تصدير CSV. | حسب النطاق §5 |
| `/observations/{obs}` | التفاصيل: النص، الصور قبل/بعد، التيكت وتايملاينه، التحققات، سجل التصنيف (الإيجينت/الموظف/الأدمن)، أزرار حسب الدور (إعادة توجيه، رفع ريسك، إغلاق بدون إجراء). | |
| `/observations/new` | زيارة كاملة من الويب: ميدانية بلاب توب أو مكتبية (§9.3). | مسجّلون |
| `/observations/review` | اعتماد التصنيفات (§9.6). | super_admin، مدير الإدارة |
| `/observations/dashboard` | داشبورد الإدارات (§12). | super_admin، ops، area (نطاقه)، مدير إدارة (إدارته) |
| `/observations/before-after` | كنا وبقينا: فرع واحد، جدول الملاحظات المفتوحة والمتحققة بصورتي قبل/بعد. | |
| `/admin/areas` | CRUD مناطق + تعيين الفروع (multi-select) + المدير. | super_admin |
| `/admin/risk-levels` | تعديل الأسماء والألوان والمهل وrequires_field_verification. بدون إضافة/حذف. | super_admin |
| `/admin/observation-categories` | CRUD فئات، مجمعة بالإدارة، ترتيب بالسحب أو أرقام. | super_admin، مدير الإدارة (إدارته) |
| `/admin/observation-catalog` | CRUD بنود، فلتر بالإدارة والفئة، تعديل الحقول الإضافية وقواعد التصعيد، تفعيل/تعطيل، `usage_count`. | super_admin، مدير الإدارة (إدارته) |

### Flutter (نفس theme وLocaleProvider)

| الشاشة | لمين |
| --- | --- |
| **ObservationVisitsScreen**: زياراتي (مسودة/مرسلة) + زر "زيارة جديدة". | كل الأدوار المُبلِّغة |
| **ObservationVisitScreen**: الفرع، check-in، قائمة الملاحظات المضافة، زر إضافة (كتالوج / حرة)، زر إرسال. | |
| **CatalogPickerSheet**: بحث في بنود الإدارة، الحقول الإضافية، صورة. | |
| **FreeTextScreen**: نص متعدد الأسطر + صور، زر "فحص". | |
| **AiReviewScreen**: النقط باقتراحاتها، تعديل فردي، "تأكيد الكل". | |
| **BranchObservationsScreen**: ملاحظات فرع (للـ area manager وstore manager وops): فلاتر حالة/ريسك، فتح التفاصيل. | |
| **VerificationScreen**: ملاحظات `waiting_verification` على الفرع، اتحلت/لسه. | الإدارة المُبلِّغة، area manager |
| إضافة تبويب "ملاحظات" في home لكل دور معني. `department_employee` اللي إدارته `is_reporting` يشوف تبويب الملاحظات جنب شاشة الفني الحالية (تعديل `home_screen.dart`). | |

## 11. API (Sanctum، نفس أسلوب الردود `{data}` / `{message}`)

```
GET  /api/observations/meta?department_id=  → {reporting_departments (المتاحة للمستخدم), risk_levels, categories, catalog_items (للإدارة المختارة أو الافتراضية), responsible_departments, areas}
GET  /api/observation-visits?status=draft|submitted
POST /api/observation-visits                {department_id?, branch_id|null, type, visit_date, latitude?, longitude?, simulated?}
GET  /api/observation-visits/{id}           → {visit, observations[]}
POST /api/observation-visits/{id}/photos    multipart {photo, kind} → {path}
POST /api/observation-visits/{id}/ai-check  {points: [{index, text}]} → {results: [...§8], classification_ids[]}
POST /api/observation-visits/{id}/observations   {items: [{catalog_item_id?, category_id?, kind, risk_level_slug?, risk_override_reason?, responsible_department_id?, title?, description, product_or_area?, extra_values?, rating?, photos: [path], ai_classification_id?}]}  (upsert للمسودة)
DELETE /api/observations/{id}               (مسودة فقط)
POST /api/observation-visits/{id}/submit    → {visit, created_tickets}
GET  /api/observations?branch_id=&status=&risk=&department_id=&from=&to=&page=
GET  /api/observations/{id}                 → {observation, photos, ticket, verifications, classification}
POST /api/observations/{id}/verify          {result, notes?, photo_path?}
GET  /api/branches/{id}/observations/pending-verification
```

كل endpoint عليه Policy. FormRequest لكل POST. (أول استخدام للـ FormRequests في المشروع؛ مقبول.)

## 12. الداشبورد والمؤشرات (تعريفات ثابتة)

المدى الزمني بـ `DateRange` + `filterbar`. كل رقم قابل للضغط يفتح القائمة مفلترة.

| مؤشر | التعريف |
| --- | --- |
| زيارات الإدارة | `observation_visits` بـ `status=submitted` و`type=field` لكل `department_id`. |
| فروع تمت زيارتها | distinct `branch_id` من نفس الزيارات. |
| ملاحظات لكل زيارة | عدد `observations` ÷ عدد الزيارات المرسلة، لكل إدارة. |
| مشاكل مفتوحة | `kind=issue` و`status in (open, in_progress, reopened)`. |
| نسبة الحل في المهلة | من التيكتات المرتبطة المقفولة: `closed_at <= due_at` ÷ المقفولة. لكل إدارة مسؤولة. |
| متأخر الآن | تيكتات مرتبطة حسب `Ticket::isOverdue()` الموجود (`due_at` فات، والحالة ليست closed ولا waiting_approval). نفس التعريف في كل الشاشات. |
| توزيع الريسك | عدد الملاحظات لكل درجة، لكل إدارة. |
| الفروع الأكثر تكراراً | `catalog_item_id × branch_id` بعدد ≥ 2 في المدى. |
| أداء الإدارة المسؤولة | متوسط ساعات `created_at → closed_at` للتيكتات بـ `source=observation`. |
| خارج الكتالوج | نسبة الملاحظات `catalog_item_id null` لكل إدارة (مؤشر على احتياج الكتالوج لبنود). |
| مقارنة الأسابيع | نفس المؤشرات لأسبوعين متتاليين، مع ملاحظة إن الفرق حجم توثيق مش بالضرورة أداء. |

**العرض:** 6 بطاقات KPI، جدول "الإدارات" (صف لكل إدارة بالمؤشرات)، رسم Chart.js للريسك حسب الإدارة، جدول الفروع المتكررة، قائمة المتأخر. نفس المكونات تتعرض مفلترة للـ area manager على منطقته ولمدير الإدارة على إدارته.

## 13. استيراد الـ 376 سجل

أمر `php artisan observations:import {xlsx}` يقرأ `Central Issues` + `Original Observations` + `Original Visits` من `StoresAi/LeVoile_Control_Center_Database_FINAL.xlsx` (يُنسخ إلى `database/data/levoile_control_center_oct2026.xlsx`). مكتبة القراءة: `maatwebsite/excel` أو `phpoffice/phpspreadsheet` (تُضاف لـ composer).

**قواعد:**

- **الفروع:** جدول mapping ثابت في الأمر من الاسم الخام (بالعربي والإنجليزي وكل التهجئات في الداتا) إلى `branch_name` الموجود. أي اسم غير معروف يوقف الاستيراد ويطبع القائمة. "All branches" → `branch_id=null`، زيارة `desk`، والنوع يُجبر `activity` (بدون تيكت) حتى لو كان Issue في المصدر، مع ذكر ذلك في الوصف.
  - Mapping مؤكد من الداتا: City Stars/سيتي ستارز → City Stars Mall؛ Mall of egypt/مول مصر/MoE → Mall of Egypt Store؛ MOE 2 → Mall of Egypt 2؛ Mall of arabia/MoA/مول العرب → Mall of Arabia؛ Zayed/زايد → Saraya Mall؛ Hegaz/hegaz → El Hegaz؛ Marghany/Merghany/الميرغني → El Marghany؛ Nozha/ELNozha/النزهة → Nozha Street؛ Abbas 1/عباس 1 → Abbas El Akkad؛ Abbas 2/عباس2/عباس 2 → Abbas El Akkad / Ezzat Salama؛ Genena Mall/جنينة/جنينه مول → Gnena Mall؛ RBT → El Rebat Mall؛ The Yard/theyard → The Yard Mall؛ Point 90/Point90 → Point 90 Mall؛ Madinaty → Open Air Mall؛ Maadi 1/2، Mokattam 1/2/Mokkatam، Smouha، Mansoura، Zagazig، Mohandessin → نظائرها.
  - **غير محسوم (ينتظر المالك):** "شهاب/Shehab" (14 سجل)، "Gamal abd el nasser" (1)، "mds" (1) غير موجودين في جدول الفروع. انظر §16.
- **الإدارات:** CCTV→cctv، Stock Control→stock_control، Maintenance→maintenance، Merchandising→merchandise، Finance→finance، IT→it، HR→hr، Loss Prevention→lp.
- **الزيارات:** تتجمّع حسب (إدارة، فرع، تاريخ مؤكد). بدون تاريخ مؤكد → زيارة `desk` بتاريخ بداية الأسبوع و`general_notes` تقول "تاريخ غير مؤكد". الـ 5 زيارات Merchandising الأصلية تتسجل بأوقاتها.
- **النوع:** Violation/Issue → issue، Training/Activity/Maintenance Task → activity، Assessment → assessment (جيد/متوسط/ضعيف → good/average/poor)، Positive → positive، Reported Finding → issue لو فيه فرع وإلا activity.
- **الفئة والبند:** mapping من `Category` + نص الملاحظة إلى الفئة والبند المزروعين (§14). ما يتطابقش → حرة بفئة "أخرى" للإدارة و`classification_status=manual`.
- **الحالة:** Done/Completed/Training Completed → `closed_no_action` (لا تيكتات لمهام الصيانة المنتهية)، Open/Reported/جاري العمل/لم يتم الحل → `open` مع تيكت، Pending Verification → `waiting_verification` مع تيكت مقفول.
- **بدون ضوضاء:** الاستيراد يمرر flag لـ `createManual` يوقف الإشعارات و`autoAssign`. `due_at` = `event_date` (أو نهاية الأسبوع لو غير مؤكد) + المهلة، فالمتأخر يظهر متأخراً بصدق. `created_by` = مستخدم نظام "Import" (دور `super_admin`، غير نشط للدخول)؛ الاعتماد والإغلاق متاح لمدير الفرع/area/ops حسب §5. لا محاولة لربط "Reported By" بمستخدمين.
- `source=import`، `import_source` = اسم الملف المصدر، `import_row` = الصف. التواريخ الخام (Excel serial) تتحول وتتحفظ في `event_date` لو كانت داخل أسبوعها، وإلا تُترك null ويُذكر في الوصف.
- الأمر idempotent: يستخدم `import_source + import_row` كمفتاح.

## 14. الكتالوج والفئات الأولية (سيدر)

يُبنى من تحليل الـ 376 سجل. الدرجات أدناه **اختياري أنا** كبداية والأدمن يعدلها.

| إدارة | فئة | ريسك | مسؤولة | أمثلة بنود |
| --- | --- | --- | --- | --- |
| CCTV | فتح الفرع | medium | operation | تأخير عن ميعاد الفتح (حقل: دقائق، موظف؛ قاعدة ≥30 → high) |
| CCTV | الكاشير | high | operation (cc: lp) | عد النقدية بمفرده، فتح الشنطة بعد غلق النور، عدم فتح الشنطة أمام الكاميرا |
| CCTV | الفلور | low | operation | عدم ارتداء النيم تاج، عدم ارتداء اسكارف اليونيفورم، ارتداء إكسسوار، استخدام الموبايل |
| CCTV | الفيتنج روم | medium | operation | دخول أكثر من 5 قطع، دخول بشنطة غير شخصية |
| CCTV | الاستوك | medium | operation | تواجد أغلب الفريق داخل الاستوك |
| CCTV | الكاميرات والـ DVR | critical | it | فصل الـ DVR، كاميرا معطلة |
| Stock Control | البضاعة التالفة | medium | operation | ديفوهات/دراي كلين غير مراجعة |
| Stock Control | الباركود والتسعير | medium | operation | قطع بدون كود، قطع بدون سعر |
| Stock Control | تنظيم المخزن | low | operation | مخزن غير منظم |
| Stock Control | جاهزية الجرد | medium | operation | غير مجهز للجرد |
| Stock Control | الدورة المستندية | medium | operation | مستندات غير محفوظة |
| Stock Control | استلام البضاعة | medium | operation | مخالفة شيت الاستلام |
| Stock Control | تدريب | none | — | (activity) تدريب على شيت … |
| Finance | الالتزام المالي | high | operation (cc: lp) | مرتجعات بدون فواتير، إهمال اليوميات، عدم إرسال الإيميلات |
| Finance | مخالفة نقدية | critical | lp | سداد شخصي من الموظف، عجز نقدية |
| Merchandise | VM | low | vm | قطعة New مع Sale، عرض يحتاج هندلة |
| Merchandise | Quality | medium | operation | آثار ميك أب/روج، زرار واقع، تنتيش |
| Merchandise | Availability | medium | warehouse | معروض X من Y لون، موديل غير معروض |
| Merchandise | Pricing | medium | operation | بدون سعر |
| Merchandise | Storage | low | operation | بدون كيس حماية، مخزن يحتاج ترتيب |
| Merchandise | Knowledge | low | operation | الفريق لا يعرف شيت Urgent/Top List |
| Merchandise | Delivery / Replenishment | medium | warehouse | لم يتم الاستلام، لم يتم Replenishment |
| Maintenance | أعمال صيانة | none (activity) / high لو "عاجل" | maintenance | كهرباء، نجارة، نقاشة، سباكة، تكييف، أبواب، مكوة، تركيبات |
| HR | متابعة تشغيلية | low | operation | ضعف دور مدير الفرع في الحضور |
| IT | متابعة تشغيلية | low | it | مشاكل شبكة/أجهزة |
| LP | تدريب | none | — | SOP / Cashier / Open & Close |
| كل إدارة | أخرى | medium | (فارغ، يحدده الإيجينت أو الموظف) | — |

السيدر `ObservationSetupSeeder` idempotent بـ `updateOrCreate` على `slug`/`code`، ويُنفّذ على اللايف بـ `db:seed --class=ObservationSetupSeeder` فقط. المناطق الأولية: تُزرع من `branches.area` الحالي كمنطقة لكل قيمة مميزة (Heliopolis، Nasr City، 5th Settlement، …) بدون مدير، والأدمن يرتبها.

## 15. ما يُترك للمنفذ

- تفاصيل الـ Blade والـ Flutter (التنسيق، الأيقونات) ضمن نفس style الموجود.
- نص الـ system prompt للإيجينت وschema الـ JSON، بشرط الالتزام بالمدخلات والمخرجات في §8.
- اختيار مكتبة قراءة Excel.
- طريقة البحث في الكتالوج على الموبايل (محلي بعد تحميل meta).
- أمر التصعيد اليومي (`observations:escalate`) ومكانه في `routes/console.php`.
- الاختبارات: Feature tests لكل endpoint وللقواعد في §7 و§13 (إنشاء `tests/` وphpunit.xml لأول مرة مقبول).

## 16. قراراتي (للمراجعة)

1. الملاحظة الحرة تتحول لتيكت فوراً بعد تأكيد الموظف؛ اعتماد الأدمن بالتوازي ويقدر يعيد التوجيه.
2. الكتالوج والفئات يعدلهم `super_admin` ومدير الإدارة لإدارته فقط.
3. التحقق الميداني إجباري لـ critical/high فقط؛ medium/low تتقفل باعتماد صورة بعد.
4. درجات الريسك 5 ثابتة بدون إضافة.
5. المناطق تُزرع من `branches.area` الحالي.
6. الموديل الافتراضي `claude-opus-5-5` عبر SDK الرسمي، effort low، طلب متزامن.
7. التيكت الناتج من الملاحظة يستخدم نفس بورد الإدارة ونفس شاشات الموبايل بدون تعديل.
8. مهام الصيانة المستوردة المنتهية ما بتعملش تيكتات.
9. إصلاح صلاحيات الإغلاق وشرط صورة بعد يطبّق على كل التيكتات داخل `changeStatus`، مع الحفاظ على اعتماد مدير الفرع وإغلاق مدير الإدارة المسؤولة.
10. تيكت واحد لكل ملاحظة لإدارة مسؤولة واحدة؛ الإدارات الإضافية `cc` بدون تيكت.
11. الملاحظة بدون فرع لا تكون `issue`؛ التيكت يتطلب فرعاً دائماً (لا تغيير في `tickets.branch_id`).
12. المستخدمون بدون إدارة يسجلون تحت `operation` افتراضياً مع إمكانية اختيار إدارة أخرى لكل زيارة.
13. `areas.manager_id` هو مصدر الحقيقة لنطاق الـ Area Manager، مع مزامنة `branch_user`.
14. الاستيراد صامت (بدون إشعارات أو تعيين تلقائي) و`due_at` نسبي لتاريخ الحدث.
15. `departments.is_reporting` يحدد من يرى الموديول.

**سؤال مفتوح للمالك:** الفروع "شهاب"، "جمال عبد الناصر"، "mds" الموجودة في الداتا وغير الموجودة في السيستم: تُضاف كفروع جديدة (بأي منطقة)، أم تُربط بفروع موجودة، أم تُستبعد سجلاتها؟

## 17. حزم العمل (للتوزيع على جلسات متوازية)

العقد المشترك = §6 (الأعمدة) و§11 (الـ API). الحزمة A لازم تخلص الأول؛ B وC وD وE يشتغلوا بالتوازي بعدها.

| حزمة | المحتوى | يعتمد على |
| --- | --- | --- |
| **A. الأساس** | Migrations، Models، Policies، `risk_levels` + `areas` + categories + catalog seeders، `tickets.source` backfill، إصلاح approve. | — |
| **B. API + الإيجينت** | Controllers/FormRequests لكل §11، `ObservationService` (حفظ، تيكت، تصعيد، تحقق)، `ObservationClassifier`، tests. | A |
| **C. ويب الأدمن** | `/admin/areas`, `/admin/risk-levels`, `/admin/observation-categories`, `/admin/observation-catalog`, `/observations/review`. | A |
| **D. ويب العمليات** | `/observations` قائمة وتفاصيل وتسجيل من المكتب وكنا وبقينا وداشبورد. | A (والـ Service من B لو جاهز، وإلا ينشئه D ويتدمج) |
| **E. Flutter** | كل شاشات §10 على عقد §11. | A + B (يقدر يبدأ على mock بعد اعتماد العقد) |
| **F. الاستيراد** | أمر `observations:import` + mapping الفروع + تشغيله على نسخة من اللايف. | A |

B وD يتشاركوا `ObservationService`؛ عشان ما يتضاربوش، B هو اللي يكتبه وD يستهلكه (D يبدأ بالشاشات القرائية الأول).
