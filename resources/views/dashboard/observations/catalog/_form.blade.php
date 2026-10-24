{{-- Catalog item form body. Used inside the modal on catalog/index. Expects $categories, $allDepartments, $riskLevels, $locked. --}}
<div class="row g-2">
    <div class="col-md-4">
        <label class="form-label small">{{ t('obs.cat.name','الفئة') }}</label>
        <select name="category_id" id="itCategory" class="form-select" required>
            @foreach($categories->groupBy('department_id') as $rows)
                <optgroup label="{{ $rows->first()->department->name }}">
                    @foreach($rows as $c)<option value="{{ $c->id }}">{{ $c->name_ar }} · {{ $c->name_en }}</option>@endforeach
                </optgroup>
            @endforeach
        </select>
    </div>
    <div class="col-md-3"><label class="form-label small">{{ t('obs.catalog.code','الكود') }}</label><input name="code" id="itCode" class="form-control text-uppercase" placeholder="CCTV-OPEN-LATE" required></div>
    <div class="col-md-2">
        <label class="form-label small">{{ t('obs.kind','النوع') }}</label>
        <select name="kind" id="itKind" class="form-select">
            @foreach(\App\Models\ObservationCategory::KINDS as $k)<option value="{{ $k }}">{{ t('obs.kind.'.$k, $k) }}</option>@endforeach
        </select>
    </div>
    <div class="col-md-3"><label class="form-label small">{{ t('obs.sort','الترتيب') }}</label><input name="sort_order" id="itSort" type="number" min="0" class="form-control"></div>
</div>
<div class="row g-2 mt-1">
    <div class="col-md-6"><label class="form-label small">{{ t('obs.catalog.label_ar','البند بالعربي') }}</label><input name="label_ar" id="itLabelAr" class="form-control" required></div>
    <div class="col-md-6"><label class="form-label small">{{ t('obs.catalog.label_en','البند بالإنجليزي') }}</label><input name="label_en" id="itLabelEn" class="form-control" required></div>
</div>
<div class="mt-2">
    <label class="form-label small">{{ t('obs.catalog.keywords','كلمات مساعدة (مفصولة بفواصل)') }}</label>
    <input name="keywords" id="itKeywords" class="form-control" placeholder="تأخير, فتح, late, opening">
    <div class="form-text">{{ t('obs.catalog.keywords_hint','تساعد البحث في الموبايل والإيجينت على التعرف على البند.') }}</div>
</div>
<div class="row g-2 mt-1">
    <div class="col-md-3">
        <label class="form-label small">{{ t('obs.risk_level','درجة الخطورة') }}</label>
        <select name="risk_level_id" id="itRisk" class="form-select">
            <option value="">{{ t('obs.from_category','من الفئة') }}</option>
            @foreach($riskLevels as $r)<option value="{{ $r->id }}">{{ app()->getLocale()==='en' ? $r->name_en : $r->name_ar }}</option>@endforeach
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label small">{{ t('obs.responsible','الإدارة المسؤولة') }}</label>
        <select name="responsible_department_id" id="itResp" class="form-select">
            <option value="">{{ t('obs.from_category','من الفئة') }}</option>
            @foreach($allDepartments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
        </select>
    </div>
    <div class="col-md-3"><label class="form-label small">{{ t('obs.sla_hours','المهلة (ساعات)') }}</label><input name="sla_hours" id="itSla" type="number" min="1" class="form-control" placeholder="{{ t('obs.from_category','من الفئة') }}"></div>
    <div class="col-md-3">
        <label class="form-label small">{{ t('obs.cc','إدارات تُبلَّغ بدون تيكت (CC)') }}</label>
        <select name="cc_department_ids[]" id="itCc" class="form-select" multiple size="3">
            @foreach($allDepartments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
        </select>
    </div>
</div>

<hr>
<div class="d-flex justify-content-between align-items-center">
    <label class="form-label small fw-bold mb-0">{{ t('obs.catalog.extra_fields','حقول إضافية') }}</label>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addFieldRow()"><i class="bi bi-plus"></i> {{ t('obs.catalog.add_field','إضافة حقل') }}</button>
</div>
<div class="form-text mb-1">{{ t('obs.catalog.extra_fields_hint','تظهر للموظف عند اختيار البند (مثل: دقائق التأخير، اسم الموظف).') }}</div>
<table class="table table-sm align-middle mb-2" id="fieldsTable">
    <thead><tr class="small text-muted"><th>key</th><th>{{ t('obs.name_ar','الاسم بالعربي') }}</th><th>{{ t('obs.name_en','الاسم بالإنجليزي') }}</th><th>{{ t('obs.catalog.field_type','النوع') }}</th><th>{{ t('obs.catalog.options','خيارات') }}</th><th>{{ t('obs.catalog.required','إجباري') }}</th><th></th></tr></thead>
    <tbody></tbody>
</table>

<div class="d-flex justify-content-between align-items-center">
    <label class="form-label small fw-bold mb-0">{{ t('obs.catalog.rules','قواعد التصعيد') }}</label>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addRuleRow()"><i class="bi bi-plus"></i> {{ t('obs.catalog.add_rule','إضافة قاعدة') }}</button>
</div>
<div class="form-text mb-1">{{ t('obs.catalog.rules_hint','لو قيمة حقل رقمي حققت الشرط، درجة الخطورة تتغير. مثال: minutes_late ≥ 30 → عالية.') }}</div>
<table class="table table-sm align-middle mb-2" id="rulesTable">
    <thead><tr class="small text-muted"><th>{{ t('obs.catalog.rule_field','الحقل') }}</th><th>{{ t('obs.catalog.rule_op','الشرط') }}</th><th>{{ t('obs.catalog.rule_value','القيمة') }}</th><th>{{ t('obs.risk_level','درجة الخطورة') }}</th><th></th></tr></thead>
    <tbody></tbody>
</table>

<div class="form-check mt-2"><input type="checkbox" name="active" value="1" class="form-check-input" id="itActive" checked><label class="form-check-label small" for="itActive">{{ t('common.active','نشط') }}</label></div>
