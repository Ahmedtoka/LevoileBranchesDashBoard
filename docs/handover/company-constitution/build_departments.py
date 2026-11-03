"""Builds levoile-constitution-departments.html: the department-blueprint page design filled with the new constitution's departments.

Each department lives in departments/<key>.js (one object literal) with departments/<key>-refs.json.
The page keeps the blueprint's three source codes, relabelled: yours = Egyptian law, std = global standard, mine = proposed design.

Run with: python -I docs/handover/company-constitution/build_departments.py   (from BranchesDashboard)
"""
import json
import shutil
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
DEPTS = ['finance', 'supply_chain', 'hr', 'ops_sales', 'it', 'audit']
OUT = HERE.parent / 'levoile-constitution-departments.html'

# (old, new) pairs applied to department-blueprint.html; each old string must occur exactly once.
SWAPS = [
    ('<title>دستور LeVoile</title>', '<title>دستور Le Voile بالإدارات</title>'),
    ('<b>دستور LeVoile</b><span>السياسات · SOP · الشيك ليست · التاسكات</span>',
     '<b>دستور Le Voile</b><span>القانون · المعيار العالمي · التصميم</span>'),
    ('<i class="dot yours"></i> من ملفاتكم <small>معتمد تلقائياً، اقتباس بالاسم</small>',
     '<i class="dot yours"></i> قانون مصري <small>ملزم، معتمد تلقائياً بسنده</small>'),
    ('<i class="dot std"></i> معيار عالمي <small>مصدره اتفتح واتحقق</small>',
     '<i class="dot std"></i> معيار عالمي <small>COSO · IIA · ISO · NIST… مصدره اتفتح</small>'),
    ('<i class="dot mine"></i> مقترح مني <small>يسدّ فجوة بينكم وبين المعيار</small>',
     '<i class="dot mine"></i> تصميم مقترح <small>مبني على المعيار لحجم Le Voile</small>'),
    ('لكل إدارة: الموجود عندكم، المعيار، المقترح — وكل بند بيتقرر فيه</small></h1>',
     'لكل إدارة: القانون، المعيار العالمي، التصميم المقترح — وكل بند بيتقرر فيه</small></h1>'),
    ('placeholder="ابحث في البنود (الخزنة، البصمة، الديفوهات…)"', 'placeholder="ابحث في البنود (تفويض، جرد، إقفال الشهر…)"'),
    ('<i class="dot yours"></i> ملفاتكم</button>', '<i class="dot yours"></i> القانون</button>'),
    ('<footer>LeVoile · إعداد 2026-10-30 · البنود العالمية من مصادر اتفتحت وتحقق منها بند بند؛ بنود "ملفاتكم" من تقارير الأسابيع الثلاثة (أكتوبر 2026). الجزاءات كلها "حسب لائحة الجزاءات المعتمدة" وبعد تحقيق مكتوب.</footer>',
     '<footer>Le Voile · مسودة نوفمبر 2026 · مبنية على بحث مستقل من مصادر عامة، من غير ملفات الشركة الداخلية · البنود القانونية محتاجة مراجعة المستشار قبل الاعتماد · الجزاءات كلها حسب لائحة العمل المعتمدة وبعد تحقيق مكتوب.</footer>'),
    ("const SRC = {yours:'من ملفاتكم', std:'معيار عالمي', mine:'مقترح مني'};",
     "const SRC = {yours:'قانون مصري', std:'معيار عالمي', mine:'تصميم مقترح'};"),
    ("const ICON = {operation:'🏬', lp:'🛡', cctv:'🎥', finance:'💵', hr:'👥', it:'🖥', maintenance:'🔧', stock_control:'📦', merchandise:'👗', vm:'🪟'};",
     "const ICON = {finance:'💵', supply_chain:'🚚', hr:'👥', ops_sales:'🏬', it:'🖥', audit:'🔍'};"),
    ("const ROUTE = [[/منع الخسائر|\\bLP\\b/i,'lp'],[/الكاميرات|CCTV/i,'cctv'],[/المالية/,'finance'],[/\\bHR\\b|الموارد البشرية/i,'hr'],[/\\bIT\\b|نظم المعلومات/i,'it'],[/الصيانة|المرافق/,'maintenance'],[/مراقبة المخزون/,'stock_control'],[/المرشندايز/,'merchandise'],[/\\bVM\\b|العرض المرئي/i,'vm'],[/التشغيل|مدير المنطقة|مدير العمليات/,'operation']];",
     "const ROUTE = [[/^المراجعة الداخلية/,'audit'],[/^المالية/,'finance'],[/^سلسلة الإمداد/,'supply_chain'],[/^الموارد البشرية/,'hr'],[/^العمليات والمبيعات/,'ops_sales'],[/^تقنية المعلومات/,'it']];"),
    ("['راجعوا «من ملفاتكم» الأول','دي حاجاتهم هم — متعتمدة تلقائياً. اسألهم: صح؟ ناقص؟ عدّل بالزرار على البند.'],",
     "['راجعوا «القانون» الأول','دي التزامات ملزمة ومتعتمدة تلقائياً. السؤال: مين صاحبها عندنا وبنثبتها بإيه؟ أي شك في التفسير يروح للمستشار.'],"),
    ("['قرروا في المقترحات','البنود الخضرا مبنية على فجوة بين ملفاتهم والمعيار؛ السبب مكتوب على كل بند.'],",
     "['قرروا في التصميم المقترح','البنود الخضرا تصميم لـ Le Voile مبني على المعيار؛ عدّل الأرقام والحدود لحجم الشركة.'],"),
    ("const KEY = 'levoile-constitution-v1';", "const KEY = 'levoile-company-constitution-departments-v1';"),
    ("const EVID = window.__EVIDENCE__ || {files:{},types:[],fallback:{}};",
     "const EVID = Object.assign({types:[],fallback:{}}, window.__EVIDENCE__, {files:{}});"),
    ("(it.s==='mine'?'اقتراح مبني على الفجوة بين ملفاتكم والمعيار':'')", "(it.s==='mine'?'تصميم مقترح لـ Le Voile':'')"),
    ("${it.s==='yours'?'الملف: ':'المصدر: '}", "${it.s==='yours'?'السند: ':'المصدر: '}"),
    ("['', all.length, 'إجمالي البنود في 10 إدارات'],", "['', all.length, `إجمالي البنود في ${DATA.length} إدارات`],"),
    ("['yours', c(x=>x.s==='yours'), 'من ملفاتكم'],", "['yours', c(x=>x.s==='yours'), 'قانون مصري'],"),
    ("['mine', c(x=>x.s==='mine'), 'مقترح لسدّ الفجوات'],", "['mine', c(x=>x.s==='mine'), 'تصميم مقترح'],"),
    ('(بنفسجي ملفاتكم، أزرق المعيار، أخضر المقترح)', '(بنفسجي القانون، أزرق المعيار، أخضر التصميم)'),
    ('اعتمد كل بنود «ملفاتكم»', 'اعتمد كل بنود «القانون»'),
    ("overview:'نظرة عامة<small>لكل إدارة: الموجود عندكم، المعيار، المقترح — وكل بند بيتقرر فيه</small>'",
     "overview:'نظرة عامة<small>لكل إدارة: القانون، المعيار العالمي، التصميم المقترح — وكل بند بيتقرر فيه</small>'"),
    ('<option value="yours">من الإدارة (في الجلسة): بنفسجي</option><option value="mine">مقترح: أخضر</option>',
     '<option value="mine">من الجلسة: أخضر</option><option value="yours">التزام قانوني: بنفسجي</option>'),
    ("s:fd.get('s')||'yours'", "s:fd.get('s')||'mine'"),
    ('download(`levoile-constitution-${today()}.html`', 'download(`levoile-constitution-departments-${today()}.html`'),
    ("""      <div class="before"><b>النهارده (من ملفات الثلاث أسابيع)</b><ul>
        <li>المخالفة بتتكتب في إكسل وعمود «الجزاء» فاضي، والرد نص حر: «هنبه عليهم تاني هم بس بينسو».</li>
        <li>المالية بتبعت المشكلة «على الجروب لمنع الخسائر» ومفيش سجل مين استلم ولا اتقفلت إمتى.</li>
        <li>نفس الموظف بيتكرر 3 و4 مرات «رغم التنبيه عدة مرات» ومفيش تصعيد تلقائي.</li>
        <li>إدارتين (التشغيل والمرشندايز) ما بعتوش أي تقرير، ومفيش طريقة تعرف ده غير بالعدّ اليدوي.</li>
        <li>الشيك ليست مقياسها «لا يوجد مشكلة» أو وصف حر، فما ينفعش يتجمع في رقم.</li>
      </ul></div>""",
     """      <div class="before"><b>من غير دستور</b><ul>
        <li>القاعدة بتتقال شفوي أو على جروب، ومفيش مالك ولا دليل ولا مؤشر.</li>
        <li>الالتزام القانوني (ضرائب، تأمينات، حماية بيانات، حماية المستهلك) بيتعرف وقت الغرامة.</li>
        <li>نفس الشخص بيطلب ويعتمد ويستلم ويدفع، فالخطأ والتلاعب ما بيبانوش.</li>
        <li>المراجعة بتبقى انطباع، ومفيش رقم يتقارن شهر بشهر.</li>
        <li>المخالفة ما لهاش إدارة بترد عليها ولا وقت تتقفل فيه.</li>
      </ul></div>"""),
    ("""      <div class="yours"><b><i class="dot yours"></i> من ملفاتكم — ${REFS.yours.length} مجموعات ملفات اتقرت</b><p>تقارير الثلاث أسابيع (أكتوبر 2026) في OldData: 29 ملف اتفتحوا ملف ملف. كل بند منهم مكتوب عليه اسم الملف، وأغلبه اقتباس حرفي. دي حاجاتكم أنتم، فهي معتمدة في الدستور تلقائياً وأنت بتعدّل فيها بس.</p></div>
      <div class="std"><b><i class="dot std"></i> معيار عالمي — ${REFS.std.length} مرجع اتفتح</b><p>3 باحثين جمعوا المعايير لكل إدارة، وبعدين 3 مدقّقين فتحوا كل مصدر وقروه وعلّموا كل بند: ✔ موجود في المصدر نصاً، ~ المبدأ موجود والرقم اتخفف، أو اتشال. البنود دي معروضة عليك: اعتمد، استبعد، أو عدّل.</p></div>
      <div class="mine"><b><i class="dot mine"></i> مقترح مني — يسدّ الفجوة</b><p>لما ملفاتكم فيها مشكلة متكررة والمعيار بيحلها بطريقة مش موجودة عندكم، كتبت بند بيربط الاتنين. كل مقترح مكتوب فيه المخالفة اللي جاي منها (بين «») عشان تعرف سببه.</p></div>
    </div>
    <div class="method">
      <div><b>1. بحث</b>3 وكلاء بحث، كل واحد 3–4 إدارات، بيدوّر على السياسات والـ SOP والشيك ليست والمؤشرات المنشورة لسلاسل تجزئة الأزياء.</div>
      <div><b>2. قراءة ملفاتكم</b>وكيل رابع قرا 29 ملف في OldData واستخرج اللي موجود فعلاً لكل إدارة بالاقتباس.</div>
      <div><b>3. تحقق</b>3 مدقّقين فتحوا كل رابط؛ أي مصدر ما اتفتحش اتشالت بنوده، وأي رقم مش في الصفحة اتشال أو بقى «حد تحدده الشركة».</div>
      <div><b>4. قابلية القياس</b>كاتب واحد صاغ كل سياسة بالستة عناصر؛ أي سياسة ما تقدرش تتكتب لها الستة اتشالت.</div>""",
     """      <div class="yours"><b><i class="dot yours"></i> قانون مصري — ${REFS.yours.length} سند</b><p>قوانين وقرارات مصرية سارية: العمل، التأمينات، الضرائب والفاتورة والإيصال الإلكتروني، حماية المستهلك، حماية البيانات. ملزمة فمتعتمدة تلقائياً، وكل تفسير فيه شك يروح للمستشار قبل التطبيق.</p></div>
      <div class="std"><b><i class="dot std"></i> معيار عالمي — ${REFS.std.length} مرجع اتفتح</b><p>معايير وأطر مهنية منشورة (COSO، IIA، ISO، NIST، EAS…) وممارسات جهات مهنية. ✔ البند موجود في المصدر نصاً، ~ المبدأ موجود والصياغة أو الرقم اتكيف. المعايير بتحدد الضابط، مش الإجراء الحرفي.</p></div>
      <div class="mine"><b><i class="dot mine"></i> تصميم مقترح — لحجم Le Voile</b><p>الـ SOP وقوائم الفحص اللي ما فيش جهة بتنشرها جاهزة، اتكتبت مبنية على المعيار ومكتوب جنبها «مبني على». الأرقام والحدود فيها بتتحدد في الجلسة.</p></div>
    </div>
    <div class="method">
      <div><b>1. بحث مستقل</b>تقرير بحثي من مصادر عامة بس، من غير ملفات الشركة، بستة مسارات: الحوكمة، المالية، سلسلة الإمداد، الموارد البشرية، العمليات والمبيعات، المراجعة والتقنية.</div>
      <div><b>2. تعميق لكل إدارة</b>باحث لكل إدارة كمّل الـ SOP وقوائم الفحص والمؤشرات من المعايير المنشورة، وفتح كل مصدر قبل ما يستشهد بيه.</div>
      <div><b>3. تحقق</b>أي رقم مش موجود في مصدر اتفتح بقى «تحدده الجهة الحاكمة»، والمصادر اللي اتشالت مكتوبة تحت.</div>
      <div><b>4. قابلية القياس</b>كل سياسة بالستة عناصر: يُكتشف، الدليل، على، المسؤول عن الرد، الجزاء، المؤشر.</div>"""),
    ('<h2><i class="dot yours" style="display:inline-block;vertical-align:middle"></i> ملفاتكم اللي اتقرت</h2>\n    <p class="lead">كل ملف: إدارة مين، فيه إيه، وإيه اللي اتاخد منه.</p>',
     '<h2><i class="dot yours" style="display:inline-block;vertical-align:middle"></i> السند القانوني</h2>\n    <p class="lead">كل قانون أو قرار: الإدارة اللي بيحكمها، المواد اللي اتاخدت، وملاحظة المستشار.</p>'),
    ('<dt>مين</dt><dd>${esc(r.who)}</dd><dt>فيه إيه</dt><dd>${esc(r.what)}</dd><dt>ملاحظة</dt>',
     '<dt>الجهة</dt><dd>${esc(r.who)}</dd><dt>المواد</dt><dd>${esc(r.what)}</dd><dt>ملاحظة</dt>'),
    # Policy explanations (w) and law references that open like the standards do.
    ('</style>\n</head>',
     '.why{margin:8px 0 2px;padding:10px 12px;border-radius:10px;background:var(--surface-2);border-inline-start:3px solid var(--line-2);font-size:.92rem;line-height:1.75}\n'
     '.why b{display:block;font-size:.8rem;color:var(--muted);margin-bottom:2px}\n'
     '.ref.yours .links a,.ref.yours .use{font-size:.85rem}\n</style>\n</head>'),
    ("data-text=\"${esc((it.t+' '+(it.n||'')+' '+(it.decisionNote||'')).toLowerCase())}\">",
     "data-text=\"${esc((it.t+' '+(it.w||'')+' '+(it.n||'')+' '+(it.decisionNote||'')).toLowerCase())}\">"),
    ('<span class="st ${it.status}">${STL[it.status]}</span></div>\n    ${meta(',
     '<span class="st ${it.status}">${STL[it.status]}</span></div>\n    ${it.w?`<div class="why"><b>الشرح</b>${esc(it.w)}</div>`:\'\'}${meta('),
    ("  return REFS.std.find(r=>r.m.some(m=>s.includes(m))) || null;\n}",
     "  return REFS.std.find(r=>r.m.some(m=>s.includes(m))) || null;\n}\n"
     "function lawFor(src){ const s=String(src||''); return REFS.yours.find(r=>(r.m||[r.k]).some(m=>s.includes(m))) || null; }"),
    ("const ref = it.s==='std' ? refFor(name) : null;",
     "const ref = it.s==='std' ? refFor(name) : (it.s==='yours' ? lawFor(name) : null);"),
    ('${REFS.yours.map(r=>`<article class="ref yours"><div class="hd">',
     '${REFS.yours.map(r=>`<article class="ref yours" id="ref-${esc(r.k)}"><div class="hd">'),
    ('<dt>ملاحظة</dt><dd>${esc(r.note)}</dd></dl></article>`).join(\'\')}',
     '<dt>ملاحظة</dt><dd>${esc(r.note)}</dd></dl>${(r.urls||[]).length?`<div class="links">${r.urls.map(([t,u])=>`<a href="${esc(u)}" target="_blank" rel="noopener">${esc(t)} ↗</a>`).join(\'\')}</div>`:\'\'}</article>`).join(\'\')}'),
    # Department structure (org) rendered as a tree above the meeting box.
    ('</style>\n</head>',
     '.org{background:var(--surface);border:1px solid var(--line);border-radius:16px;padding:18px 20px;margin:16px 0}\n'
     '.org h3{margin:0 0 4px}.org .lead{margin:0 0 10px;color:var(--muted)}\n'
     '.org .rt{display:inline-block;margin:0 0 12px;padding:6px 10px;border-radius:8px;background:var(--surface-2);font-size:.9rem}\n'
     '.org table{width:100%;border-collapse:collapse;font-size:.88rem;margin:6px 0 14px}.org td,.org th{border-bottom:1px solid var(--line);padding:6px 8px;text-align:start;vertical-align:top}\n'
     '.tree,.tree ul{list-style:none;margin:0;padding:0}.tree ul{margin-inline-start:22px;border-inline-start:2px solid var(--line-2);padding-inline-start:12px}\n'
     '.tree li{margin:6px 0}.role{display:flex;flex-wrap:wrap;gap:6px 12px;align-items:baseline;border:1px solid var(--line);border-inline-start:4px solid var(--std);border-radius:10px;padding:8px 12px;background:var(--surface)}\n'
     '.role.mine{border-inline-start-color:var(--mine)}.role b{font-size:.98rem}.role .en{color:var(--muted);font-size:.82rem;direction:ltr}\n'
     '.role .lv{font-size:.75rem;padding:1px 8px;border-radius:99px;background:var(--surface-2)}.role .hc{margin-inline-start:auto;font-weight:700;color:var(--brand-ink)}\n'
     '.role .job{flex-basis:100%;font-size:.86rem;color:var(--ink-2,inherit)}.role .rs{flex-basis:100%;font-size:.78rem;color:var(--muted)}\n'
     '.org .phases{margin-top:12px;padding:10px 12px;border-radius:10px;background:var(--surface-2);font-size:.9rem}\n</style>\n</head>'),
    ('function deptHtml(d, i){',
     "function orgTree(roles, parent){\n"
     "  const kids = roles.filter(r=>(r.parent||null)===parent);\n"
     "  return kids.length ? `<ul>${kids.map(r=>`<li><div class=\"role ${r.s==='mine'?'mine':''}\"><b>${esc(r.ar)}</b><span class=\"en\">${esc(r.en||'')}</span><span class=\"lv\">${esc(r.level||'')}</span><span class=\"hc\">${esc(r.n||'')}</span>${r.job?`<span class=\"job\">${esc(r.job)}</span>`:''}${r.src?`<span class=\"rs\">${r.s==='mine'?'تصميم: ':'المرجع: '}${esc(r.src)}</span>`:''}</div>${orgTree(roles, r.id)}</li>`).join('')}</ul>` : '';\n"
     "}\n"
     "function orgHtml(d, i){\n"
     "  const o = d.org; if(!o) return '';\n"
     "  return `<section class=\"org\" id=\"p-${i}-org\"><h3>🏛 هيكل الإدارة<small> التصور العالمي لشركة تجزئة بحوالي 400 موظف، قبل هيكلكم الفعلي</small></h3>\n"
     "    ${o.summary?`<p class=\"lead\">${esc(o.summary)}</p>`:''}${o.reportsTo?`<div class=\"rt\"><b>تتبع:</b> ${esc(o.reportsTo)}</div>`:''}\n"
     "    ${(o.sizing||[]).length?`<table><tr><th>مقياس الحجم</th><th>القيمة على 400 موظف</th><th>المرجع</th></tr>${o.sizing.map(z=>`<tr><td>${esc(z.t)}</td><td>${esc(z.v)}</td><td>${z.u?`<a href=\"${esc(z.u)}\" target=\"_blank\" rel=\"noopener\">${esc(z.src||'رابط')}</a>`:esc(z.src||'')}</td></tr>`).join('')}</table>`:''}\n"
     "    <div class=\"tree\">${orgTree(o.roles||[], null)}</div>\n"
     "    ${o.phases?`<div class=\"phases\"><b>المراحل:</b> ${esc(o.phases)}</div>`:''}\n"
     "    ${(o.sources||[]).length?`<details class=\"sources\"><summary>مصادر الهيكل (${o.sources.length})</summary><ol>${o.sources.map(s=>`<li>${esc(s.t)} — <a href=\"${esc(s.u)}\" target=\"_blank\" rel=\"noopener\">${esc(s.u)}</a></li>`).join('')}</ol></details>`:''}\n"
     "  </section>`;\n"
     "}\n"
     "function deptHtml(d, i){"),
    ('    ${meetingHtml(d)}\n    <div class="pillar-nav">${PILLARS.map(p=>`<a href="#p-${i}-${p.k}">${p.ic} ${p.title}<span class="n">${effective(d,p.k).length}</span></a>`).join(\'\')}</div>',
     '    ${orgHtml(d,i)}\n    ${meetingHtml(d)}\n    <div class="pillar-nav">${d.org?`<a href="#p-${i}-org">🏛 الهيكل<span class="n">${(d.org.roles||[]).length}</span></a>`:\'\'}${PILLARS.map(p=>`<a href="#p-${i}-${p.k}">${p.ic} ${p.title}<span class="n">${effective(d,p.k).length}</span></a>`).join(\'\')}</div>'),
    ('مفيش «معيار دولي» واحد لتشغيل محلات الأزياء زي ISO؛ المعرفة دي منشورة عند جهات الصناعة والمزوّدين اللي بيخدموا السلاسل. عشان كده كل مرجع معلّم بنوعه، وأي رقم منه بيتعامل كمرجع مقارنة لحد ما تعتمده الشركة.',
     'كل مرجع معلّم بنوعه ودرجة الثقة فيه. المعايير المهنية (COSO، IIA، ISO) بتحدد الضوابط، وأي رقم من مزوّد أو استشاري بيتعامل كمرجع مقارنة لحد ما تعتمده الشركة.'),
]


def script(text):
    return '<script>\n' + text.replace('</script', '<\\/script') + '\n</script>'


def dept(key):
    body = (HERE / 'departments' / f'{key}.js').read_text(encoding='utf-8').strip().rstrip(';')
    org = HERE / 'departments' / f'{key}-org.json'
    if not org.exists():
        return body
    return f'Object.assign({body}, {{org: {org.read_text(encoding="utf-8").strip()}}})'


def merge_refs():
    std, laws, dropped = {}, [], []
    for key in DEPTS:
        r = json.loads((HERE / 'departments' / f'{key}-refs.json').read_text(encoding='utf-8'))
        for ref in r.get('std', []):
            have = std.get(ref['k'])
            if not have:
                std[ref['k']] = dict(ref, m=list(ref['m']), urls=list(ref['urls']))
                continue
            have['m'] += [m for m in ref['m'] if m not in have['m']]
            have['urls'] += [u for u in ref['urls'] if u[1] not in {x[1] for x in have['urls']}]
        laws += [dict(x, dept=x.get('dept', key)) for x in r.get('yours', [])]
        dropped += r.get('dropped', [])
    types = {
        'body': {'t': 'جهة مهنية / حكومية / بحثية', 'trust': 'ثقة عالية', 'd': 'جهة بتصدر معيار أو قانون أو بحث، ومش بتبيع منتج.'},
        'expert': {'t': 'متخصص', 'trust': 'عملي، خبرة تخصصية', 'd': 'جهة متخصصة في المجال بتنشر ممارسات.'},
        'vendor': {'t': 'شركة استشارات / برمجيات', 'trust': 'عملي، يُراجع', 'd': 'شركة بتنشر ملخصات أو قوائم لعملائها؛ الأرقام مرجع مقارنة لا قانون.'},
        'guide': {'t': 'دليل شارح لمعيار', 'trust': 'متوسطة، يُراجع مع الأصل', 'd': 'موقع بيشرح معيار رسمي؛ المبدأ معتمد والرقم يُراجع مع النص الأصلي.'},
    }
    return {'types': types, 'std': list(std.values()), 'yours': laws, 'dropped': dropped}


def main():
    page = (HERE.parent / 'department-blueprint.html').read_text(encoding='utf-8')
    for old, new in SWAPS:
        assert page.count(old) == 1, ('swap', old[:60], page.count(old))
        page = page.replace(old, new)
    depts = ',\n'.join(dept(k) for k in DEPTS)
    data = 'window.__DEPT_DATA__ = [\n' + depts + '\n];'
    refs = 'window.__REFS__ = ' + json.dumps(merge_refs(), ensure_ascii=False) + ';'
    evidence = (HERE.parent / 'department-blueprint-evidence.js').read_text(encoding='utf-8')
    tags = '<script src="department-blueprint-data.js"></script>\n<script src="department-blueprint-refs.js"></script>\n<script src="department-blueprint-evidence.js"></script>'
    assert page.count(tags) == 1
    page = page.replace(tags, '\n'.join(script(x) for x in (data, refs, evidence)))
    OUT.write_text(page, encoding='utf-8')
    shutil.copyfile(OUT, ROOT / 'public' / 'handover' / OUT.name)
    print(OUT, len(page))


if __name__ == '__main__':
    main()
