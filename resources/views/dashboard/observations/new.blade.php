@extends('layouts.app')
@section('title', dt('تسجيل زيارة', 'Record a visit'))

@section('content')
<a href="{{ route('observations.index') }}" class="text-muted small text-decoration-none"><i class="bi bi-arrow-right"></i> {{ dt('الملاحظات', 'Observations') }}</a>
<div class="d-flex flex-wrap align-items-center gap-2 mt-1 mb-3">
    <h4 class="fw-bold mb-0">{{ dt('تسجيل زيارة', 'Record a visit') }}</h4>
    <span id="obs-visit-codes" class="text-muted small"></span>
    <span id="obs-save-indicator" class="small text-muted ms-auto" aria-live="polite"></span>
</div>

<div id="obs-alert" class="alert alert-danger small d-none" role="alert"></div>

<div id="obs-editor">
    {{-- 1. The visit: type, department, date, branch(es) --}}
    <div class="card p-3 mb-3">
        <div class="row g-3 align-items-end">
            <div class="col-md-auto">
                <label class="form-label small text-muted d-block">{{ dt('نوع الزيارة', 'Visit type') }}</label>
                <div class="btn-group" role="group">
                    <input type="radio" class="btn-check" name="obs-type" id="obs-type-field" value="field" checked>
                    <label class="btn btn-outline-primary" for="obs-type-field"><i class="bi bi-geo-alt"></i> {{ dt('ميدانية', 'Field') }}</label>
                    <input type="radio" class="btn-check" name="obs-type" id="obs-type-desk" value="desk">
                    <label class="btn btn-outline-primary" for="obs-type-desk"><i class="bi bi-laptop"></i> {{ dt('مكتبية', 'Desk') }}</label>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted" for="obs-department">{{ dt('الإدارة المُبلِّغة', 'Reporting department') }}</label>
                <select id="obs-department" class="form-select"></select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted" for="obs-date">{{ dt('تاريخ الزيارة', 'Visit date') }}</label>
                <input type="date" id="obs-date" class="form-control">
            </div>
            <div class="col-md">
                <label class="form-label small text-muted" for="obs-branch">{{ dt('الفرع', 'Branch') }}</label>
                <select id="obs-branch" class="form-select"></select>
                <div id="obs-desk-branches" class="d-none">
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" id="obs-all-branches">
                        <label class="form-check-label" for="obs-all-branches">{{ dt('كل الفروع', 'All branches') }}</label>
                    </div>
                    <select id="obs-branches" class="form-select" multiple size="4"></select>
                    <div class="form-text">{{ dt('اختر فرعاً أو أكثر (Ctrl للاختيار المتعدد) — زيارة لكل فرع.', 'Pick one or more branches (Ctrl for multiple) — one visit per branch.') }}</div>
                </div>
            </div>
            <div class="col-md-auto" id="obs-checkin-wrap">
                <button type="button" id="obs-checkin" class="btn btn-success"><i class="bi bi-box-arrow-in-left"></i> {{ dt('تسجيل الوصول', 'Check in') }}</button>
            </div>
        </div>
        <div id="obs-all-branches-note" class="alert alert-warning small mt-3 mb-0 d-none">
            <i class="bi bi-info-circle"></i> {{ dt('بدون فرع لا يمكن فتح تيكتات — تُسجَّل كأنشطة', 'Without a branch no ticket can be opened — rows are recorded as activities') }}
        </div>
        <div id="obs-visit-status" class="mt-3 d-none" aria-live="polite"></div>
    </div>

    <div class="row g-3 mb-3">
        {{-- 2. Catalog picker --}}
        <div class="col-lg-6">
            <div class="card p-3 h-100">
                <h6 class="fw-bold">{{ dt('من الكتالوج', 'From the catalog') }}</h6>
                <input type="search" id="obs-catalog-search" class="form-control form-control-sm mb-2" placeholder="{{ dt('ابحث ببند أو كلمة…', 'Search an item or keyword…') }}">
                <div id="obs-catalog-list" class="overflow-auto" style="max-height: 320px"></div>
            </div>
        </div>
        {{-- 3. Free text + AI check --}}
        <div class="col-lg-6">
            <div class="card p-3 h-100">
                <h6 class="fw-bold">{{ dt('كتابة حرة', 'Free text') }}</h6>
                <textarea id="obs-free-text" class="form-control mb-2" rows="5" dir="auto" placeholder="{{ dt('سطر لكل نقطة', 'One line per point') }}"></textarea>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" id="obs-ai-check" class="btn btn-primary btn-sm"><i class="bi bi-stars"></i> {{ dt('فحص', 'Check') }}</button>
                    <button type="button" id="obs-add-plain" class="btn btn-outline-secondary btn-sm">{{ dt('إضافة بدون فحص', 'Add without check') }}</button>
                </div>
                <div id="obs-ai-panel" class="mt-3"></div>
            </div>
        </div>
    </div>

    {{-- 4. The draft rows --}}
    <div class="card p-3 mb-3">
        <div class="d-flex align-items-center mb-2">
            <h6 class="fw-bold mb-0">{{ dt('ملاحظات الزيارة', 'Visit observations') }}</h6>
            <span id="obs-rows-count" class="badge text-bg-light border ms-2">0</span>
        </div>
        <div id="obs-rows"></div>
    </div>

    <div class="d-flex justify-content-end mb-4">
        <button type="button" id="obs-submit" class="btn btn-lg btn-success"><i class="bi bi-send"></i> {{ dt('إرسال الزيارة', 'Submit the visit') }}</button>
    </div>
</div>

{{-- 5. After submit --}}
<div id="obs-success" class="d-none"></div>
@endsection

@push('scripts')
@php
    // Every string the JS shows, in the current locale (the JS hardcodes none).
    $i18n = [
    'select' => dt('— اختر —', '— Select —'),
    'none' => dt('بدون', 'None'),
    'choose_branch' => dt('اختر الفرع', 'Choose the branch'),
    'branch_required' => dt('اختر الفرع أولاً.', 'Choose the branch first.'),
    'branches_required' => dt('اختر فرعاً واحداً على الأقل أو "كل الفروع".', 'Choose at least one branch or "All branches".'),
    'checked_in' => dt('تم تسجيل الوصول', 'Checked in'),
    'visit' => dt('زيارة', 'Visit'),
    'all_branches' => dt('كل الفروع', 'All branches'),
    'saving' => dt('جارٍ الحفظ…', 'Saving…'),
    'saved' => dt('تم الحفظ', 'Saved'),
    'save_failed' => dt('تعذر الحفظ — راجع الأخطاء', 'Could not save — check the errors'),
    'save_retrying' => dt('تعذر الحفظ — جارٍ إعادة المحاولة…', 'Could not save — retrying…'),
    'visits_missing' => dt('لم تُنشأ كل الزيارات بعد، ستُعاد المحاولة مع الإضافة أو الحفظ التالي.', 'Not every visit was created yet; it is retried with the next add or save.'),
    'not_created' => dt('لم تُنشأ', 'Not created'),
    'draft' => dt('مسودة', 'Draft'),
    'submitted_status' => dt('تم الإرسال', 'Submitted'),
    'partial_submit' => dt('بعض الزيارات أُرسلت وبعضها لا يزال مسودة — التعديلات تُحفظ على المسودات فقط. صحّح ثم أعد الإرسال لإكمالها.', 'Some visits were submitted, others are still drafts — edits only reach the drafts. Fix and submit again to finish them.'),
    'delete_failed' => dt('تعذر حذف ملاحظة من الخادم:', 'Could not delete an observation on the server:'),
    'delete_blocked' => dt('ملاحظة محذوفة لم تُحذف من الخادم بعد — لا يمكن الإرسال قبل حذفها. أعد المحاولة.', 'A deleted observation is still on the server — the visit cannot be submitted until it is removed. Try again.'),
    'link_removed' => dt('أُزيل ربط البند/الفئة لأنه ليس نشاطاً — بدون فرع تُسجَّل الملاحظة كنشاط.', 'The item/category link was removed because it is not an activity — without a branch the row is recorded as an activity.'),
    'network' => dt('تعذر الاتصال بالخادم.', 'Could not reach the server.'),
    'session_expired' => dt('انتهت الجلسة، أعد تحميل الصفحة.', 'The session expired, reload the page.'),
    'no_items' => dt('لا توجد بنود.', 'No items.'),
    'no_rows' => dt('لم تُضف ملاحظات بعد. اختر من الكتالوج أو اكتب نقاطاً.', 'No observations yet. Pick from the catalog or write points.'),
    'write_points' => dt('اكتب نقطة واحدة على الأقل.', 'Write at least one point.'),
    'checking' => dt('جارٍ الفحص…', 'Checking…'),
    'ai_unavailable' => dt('الفحص غير متاح، اختر الفئة يدوياً', 'Check unavailable, pick the category manually'),
    'you_mean' => dt('قصدك:', 'You mean:'),
    'use_item' => dt('استخدم البند', 'Use the item'),
    'suggested' => dt('مقترح', 'Suggested'),
    'confirm_all' => dt('تأكيد الكل', 'Confirm all'),
    'cancel' => dt('إلغاء', 'Cancel'),
    'category' => dt('الفئة', 'Category'),
    'kind' => dt('النوع', 'Kind'),
    'risk' => dt('الخطورة', 'Risk'),
    'responsible' => dt('الإدارة المسؤولة', 'Responsible department'),
    'description' => dt('الوصف', 'Description'),
    'product_or_area' => dt('المنتج / المكان (اختياري)', 'Product / area (optional)'),
    'rating' => dt('التقييم', 'Rating'),
    'override_reason' => dt('سبب تغيير الخطورة', 'Reason for changing the risk'),
    'override_hint' => dt('الخطورة الافتراضية:', 'Default risk:'),
    'photos' => dt('صور', 'Photos'),
    'uploading' => dt('جارٍ رفع الصور…', 'Uploading photos…'),
    'delete' => dt('حذف', 'Delete'),
    'from_category' => dt('من الفئة', 'From the category'),
    'catalog' => dt('كتالوج', 'Catalog'),
    'free' => dt('نص حر', 'Free text'),
    'other' => dt('أخرى', 'Other'),
    'submitting' => dt('جارٍ الإرسال…', 'Submitting…'),
    'confirm_submit' => dt('إرسال الزيارة؟ لن يمكن تعديلها بعد الإرسال.', 'Submit the visit? It cannot be edited afterwards.'),
    'fix_errors' => dt('صحّح الأخطاء الظاهرة بجوار الملاحظات ثم أعد الإرسال.', 'Fix the errors shown next to the observations, then submit again.'),
    'submitted' => dt('تم إرسال الزيارة', 'Visit submitted'),
    'created_tickets' => dt('التيكتات المفتوحة', 'Tickets opened'),
    'no_tickets' => dt('لم تُفتح تيكتات.', 'No tickets were opened.'),
    'observations' => dt('الملاحظات', 'Observations'),
    'register' => dt('سجل الملاحظات', 'Observations register'),
    'new_visit' => dt('زيارة جديدة', 'New visit'),
    'kinds' => collect(\App\Models\Observation::KINDS)->mapWithKeys(fn ($k) => [$k => \App\Support\ObservationLabels::kind($k)])->all(),
    'ratings' => [
        'good' => dt('جيد', 'Good'),
        'average' => dt('متوسط', 'Average'),
        'poor' => dt('ضعيف', 'Poor'),
    ],
];
@endphp
<script>
window.OBS_META = @json($meta);
window.OBS_CONFIG = @json($config);
window.OBS_I18N = @json($i18n);
</script>
<script src="{{ asset('js/observations-visit.js') }}"></script>
@endpush
