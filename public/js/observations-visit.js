/*
 * /observations/new: record a visit from a laptop (spec 9.3).
 * Vanilla JS, no build step. Talks to Plan B's API mounted under the web session at /web-api
 * (session cookie + X-CSRF-TOKEN). Server shapes: see README-observations.md "API contract".
 *
 * Sections: 1 state · 2 helpers · 3 api() · 4 visit setup · 5 catalog picker · 6 free text + AI check
 *           7 draft rows · 8 auto-save · 9 submit · 10 boot
 */
(function () {
    'use strict';

    // ===================================================================== 1. State
    const CFG = window.OBS_CONFIG;
    const T = window.OBS_I18N;
    const KINDS = Object.keys(T.kinds);
    const RATINGS = Object.keys(T.ratings);

    const S = {
        meta: window.OBS_META,
        type: 'field',
        departmentId: window.OBS_META.department_id,
        visitDate: CFG.today,
        branchId: null,          // field
        branchIds: [],           // desk, several branches
        allBranches: false,      // desk, "all branches" -> branch_id null, ONE visit
        targets: null,           // [{branch_id, branch_name, visit, error, transient}] fixed by the first add / check-in
        visits: [],              // the created ones: [{id, code, branch_id, branch_name, submitted, result}]
        creating: null,          // promise while the visits are being created
        rows: [],                // the draft rows (see newRow)
        ai: null,                // {status, message, points: [...]} after "فحص"
        saveTimer: null,
        savePromise: null,
        savePending: false,
        dirty: false,            // an edit not yet saved by a successful upsert
        editSeq: 0,
        retryTimer: null,
        retryStep: 0,
        pendingDeletes: [],
        ghosts: new Set(),       // rows deleted in the page whose DELETE failed (still on the server)
        done: false,
    };
    let uidSeq = 0;

    // ===================================================================== 2. Helpers
    const $ = (sel) => document.querySelector(sel);
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const isEn = CFG.locale === 'en';
    const nameOf = (o) => (o ? (isEn ? (o.name_en || o.name_ar) : o.name_ar) : '');
    const labelOf = (o) => (o ? (isEn ? (o.label_en || o.label_ar) : o.label_ar) : '');
    const catalogById = (id) => S.meta.catalog_items.find((i) => i.id === Number(id)) || null;
    const categoryById = (id) => S.meta.categories.find((c) => c.id === Number(id)) || null;
    const riskById = (id) => S.meta.risk_levels.find((r) => r.id === Number(id)) || null;
    const riskBySlug = (slug) => S.meta.risk_levels.find((r) => r.slug === slug) || null;
    const branchName = (id) => (S.meta.branches.find((b) => b.id === Number(id)) || {}).name || '';
    const numOrNull = (v) => (v === '' || v === null || v === undefined ? null : Number(v));
    const hhmm = () => new Date().toTimeString().slice(0, 5);
    /** Desk visit for every branch: branch_id null, one visit, activities only. */
    const allMode = () => S.type === 'desk' && S.allBranches;
    /** All-branches mode only offers what is already an activity, so the server keeps kind=activity by precedence. */
    const catalogForMode = () => S.meta.catalog_items.filter((i) => !allMode() || i.kind === 'activity');
    const categoriesForMode = () => S.meta.categories.filter((c) => !allMode() || c.default_kind === 'activity');
    const isTransient = (e) => e.status === 0 || e.status === 408 || e.status === 429 || e.status >= 500;

    function options(list, value, valueOf, textOf, withBlank) {
        const blank = withBlank ? `<option value="">${esc(T.select)}</option>` : '';
        return blank + list.map((o) => {
            const v = valueOf(o);
            return `<option value="${esc(v)}"${String(v) === String(value ?? '') ? ' selected' : ''}>${esc(textOf(o))}</option>`;
        }).join('');
    }

    function showAlert(message) {
        const box = $('#obs-alert');
        box.textContent = message || '';
        box.classList.toggle('d-none', !message);
        if (message) box.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function setIndicator(state) {
        const el = $('#obs-save-indicator');
        el.classList.toggle('text-danger', state === 'failed');
        el.classList.toggle('text-warning', state === 'retrying');
        el.textContent = state === 'saving' ? T.saving : state === 'saved' ? `${T.saved} ${hhmm()}`
            : state === 'failed' ? T.save_failed : state === 'retrying' ? T.save_retrying : '';
    }

    /**
     * What the server will apply to a row (spec 7 precedence: catalog item -> category -> the row's own value),
     * so the page shows the same kind / risk / responsible department the server stores.
     */
    function effective(row) {
        const item = row.catalog_item_id ? catalogById(row.catalog_item_id) : null;
        const category = item ? categoryById(item.category_id) : categoryById(row.category_id);
        const policyRisk = riskById(item?.risk_level_id ?? category?.default_risk_level_id ?? null);
        const lockedResponsible = item?.responsible_department_id ?? category?.default_responsible_department_id ?? null;
        const chosenRisk = row.risk_level_slug || policyRisk?.slug || '';
        return {
            item,
            category,
            kind: allMode() ? 'activity' : (item?.kind ?? category?.default_kind ?? row.kind),
            kindLocked: !!(item || category),
            policyRisk,
            chosenRisk,
            needsReason: !!(policyRisk && chosenRisk && chosenRisk !== policyRisk.slug),
            responsible: lockedResponsible ?? row.responsible_department_id,
            responsibleLocked: lockedResponsible !== null,
        };
    }

    // ===================================================================== 3. api()
    async function api(method, path, body) {
        const token = document.querySelector('meta[name="csrf-token"]')?.content || CFG.csrf;
        const opts = {
            method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token },
        };
        if (body instanceof FormData) {
            opts.body = body;
        } else if (body !== undefined) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }

        let res;
        try {
            res = await fetch(CFG.urls.api + path, opts);
        } catch (e) {
            throw Object.assign(new Error(T.network), { status: 0, errors: {} });
        }
        let data = null;
        try { data = await res.json(); } catch (e) { data = null; }
        if (!res.ok) {
            const message = res.status === 419 || res.status === 401 ? T.session_expired : (data?.message || T.network);
            throw Object.assign(new Error(message), { status: res.status, errors: data?.errors || {} });
        }
        return data;
    }

    // ===================================================================== 4. Visit setup
    function renderSetup() {
        const m = S.meta;
        $('#obs-department').innerHTML = options(m.reporting_departments, S.departmentId, (d) => d.id, (d) => d.name, false);
        $('#obs-date').value = S.visitDate;
        $('#obs-branch').innerHTML = `<option value="">${esc(T.choose_branch)}</option>`
            + options(m.branches, S.branchId, (b) => b.id, (b) => b.name, false);
        $('#obs-branches').innerHTML = m.branches.map((b) =>
            `<option value="${b.id}"${S.branchIds.includes(b.id) ? ' selected' : ''}>${esc(b.name)}</option>`).join('');
        applyMode();
    }

    function applyMode() {
        const desk = S.type === 'desk';
        $('#obs-branch').classList.toggle('d-none', desk);
        $('#obs-desk-branches').classList.toggle('d-none', !desk);
        $('#obs-checkin-wrap').classList.toggle('d-none', desk);
        $('#obs-branches').disabled = S.allBranches || S.visits.length > 0;
        $('#obs-all-branches-note').classList.toggle('d-none', !allMode());
    }

    function lockSetup() {
        document.querySelectorAll('input[name="obs-type"], #obs-department, #obs-date, #obs-branch, #obs-branches, #obs-all-branches')
            .forEach((el) => { el.disabled = true; });
        const btn = $('#obs-checkin');
        btn.disabled = true;
        btn.innerHTML = `<i class="bi bi-check2-circle"></i> ${esc(T.checked_in)}`;
        $('#obs-visit-codes').textContent = S.visits.map((v) => `${v.code} · ${v.branch_name || T.all_branches}`).join(' — ');
    }

    async function onDepartmentChange(id) {
        S.departmentId = Number(id);
        try {
            S.meta = await api('GET', `/observations/meta?department_id=${encodeURIComponent(id)}`);
            S.ai = null;
            renderCatalog();
            renderAi();
            showAlert('');
        } catch (e) {
            showAlert(e.message);
        }
    }

    /**
     * The first add / check-in fixes the targets: one per branch in desk-multi mode, one with branch_id null for
     * "all branches". Each call creates the targets still missing (a failed one is retried by the next add / save /
     * submit) and resolves true only when every target visit exists.
     */
    function ensureVisits() {
        if (S.creating) return S.creating;
        if (!S.targets) {
            let branchIds;
            if (S.type === 'field') {
                if (!S.branchId) { showAlert(T.branch_required); return Promise.resolve(false); }
                branchIds = [S.branchId];
            } else if (allMode()) {
                branchIds = [null];
            } else {
                if (!S.branchIds.length) { showAlert(T.branches_required); return Promise.resolve(false); }
                branchIds = S.branchIds.slice();
            }
            S.targets = branchIds.map((b) => ({ branch_id: b, branch_name: b ? branchName(b) : null, visit: null, error: '', transient: false }));
        }
        const missing = S.targets.filter((t) => !t.visit);
        if (!missing.length) return Promise.resolve(true);

        S.creating = (async () => {
            for (const t of missing) {
                const body = { department_id: S.departmentId, branch_id: t.branch_id, type: S.type, visit_date: S.visitDate };
                if (S.type === 'field') body.simulated = true; // manual check-in from the laptop, no GPS
                try {
                    const res = await api('POST', '/observation-visits', body);
                    t.visit = { id: res.visit.id, code: res.visit.code, branch_id: t.branch_id, branch_name: res.visit.branch?.name || t.branch_name, submitted: false, result: null };
                    S.visits.push(t.visit);
                    t.error = '';
                } catch (e) {
                    t.error = Object.values(e.errors).flat()[0] || e.message;
                    t.transient = isTransient(e);
                }
            }
            const all = S.targets.every((t) => t.visit);
            if (S.visits.length) {
                lockSetup();
                if (all && S.rows.length) scheduleSave(); // a visit created late still needs the rows
            } else {
                S.targets = null; // nothing created yet: the setup stays editable
            }
            if (all) showAlert('');
            else showAlert(`${T.visits_missing} ${missing.filter((t) => t.error).map((t) => t.error).join(' ')}`);
            renderVisitStatus();
            return all;
        })().finally(() => { S.creating = null; });
        return S.creating;
    }

    /** Per-visit status (branch, code, draft / submitted / not created, error): shown for several visits or any problem. */
    function renderVisitStatus() {
        const box = $('#obs-visit-status');
        const ts = S.targets || [];
        const partial = S.visits.some((v) => v.submitted) && S.visits.some((v) => !v.submitted);
        const show = ts.length > 1 || ts.some((t) => !t.visit || t.error);
        box.classList.toggle('d-none', !show);
        if (!show) { box.innerHTML = ''; return; }
        box.innerHTML = `
            ${partial ? `<div class="alert alert-warning small py-2 mb-2">${esc(T.partial_submit)}</div>` : ''}
            <ul class="list-unstyled small mb-0">${ts.map((t) => {
                const [color, text] = !t.visit ? ['danger', T.not_created] : t.visit.submitted ? ['success', T.submitted_status] : ['secondary', T.draft];
                return `<li class="mb-1"><span class="badge text-bg-${color}">${esc(text)}</span> ${esc(t.branch_name || T.all_branches)}`
                    + `${t.visit ? ` · ${esc(t.visit.code)}` : ''}${t.error ? ` — <span class="text-danger">${esc(t.error)}</span>` : ''}</li>`;
            }).join('')}</ul>`;
    }

    function wireSetup() {
        document.querySelectorAll('input[name="obs-type"]').forEach((r) => r.addEventListener('change', (e) => {
            S.type = e.target.value;
            applyMode();
            stripNonActivity();
            renderCatalog();
            renderRows();
            renderAi();
        }));
        $('#obs-department').addEventListener('change', (e) => onDepartmentChange(e.target.value));
        $('#obs-date').addEventListener('change', (e) => { S.visitDate = e.target.value || CFG.today; });
        $('#obs-branch').addEventListener('change', (e) => { S.branchId = numOrNull(e.target.value); });
        $('#obs-branches').addEventListener('change', (e) => {
            S.branchIds = Array.from(e.target.selectedOptions).map((o) => Number(o.value));
        });
        $('#obs-all-branches').addEventListener('change', (e) => {
            S.allBranches = e.target.checked;
            applyMode();
            stripNonActivity();
            renderCatalog();
            renderRows();
            renderAi();
        });
        $('#obs-checkin').addEventListener('click', () => ensureVisits());
    }

    // ===================================================================== 5. Catalog picker
    function renderCatalog() {
        const q = $('#obs-catalog-search').value.trim().toLowerCase();
        const match = (i) => !q || [i.label_ar, i.label_en, ...(i.keywords || [])].some((t) => String(t || '').toLowerCase().includes(q));
        const items = catalogForMode();
        const groups = S.meta.categories.map((c) => ({ c, items: items.filter((i) => i.category_id === c.id && match(i)) }))
            .filter((g) => g.items.length);

        $('#obs-catalog-list').innerHTML = groups.length ? groups.map((g) => `
            <div class="mb-2">
                <div class="small fw-semibold text-muted border-bottom mb-1">${esc(nameOf(g.c))}</div>
                ${g.items.map((i) => `
                    <button type="button" class="btn btn-sm btn-light border text-start d-block w-100 mb-1" data-catalog="${i.id}">
                        <i class="bi bi-plus-circle text-primary"></i> ${esc(labelOf(i))}
                        <span class="badge text-bg-light border float-end">${esc(T.kinds[i.kind] || i.kind)}</span>
                    </button>`).join('')}
            </div>`).join('') : `<div class="text-muted small">${esc(T.no_items)}</div>`;
    }

    async function addCatalogRow(itemId) {
        const item = catalogById(itemId);
        if (!item || !(await ensureVisits())) return;
        S.rows.push(newRow({ source: 'catalog', catalog_item_id: item.id, kind: item.kind, description: item.label_ar }));
        renderRows();
        scheduleSave();
    }

    function wireCatalog() {
        $('#obs-catalog-search').addEventListener('input', renderCatalog);
        $('#obs-catalog-list').addEventListener('click', (e) => {
            const btn = e.target.closest('[data-catalog]');
            if (btn) addCatalogRow(btn.dataset.catalog);
        });
    }

    // ===================================================================== 6. Free text + AI check
    function freeTextPoints() {
        return $('#obs-free-text').value.split('\n').map((t) => t.trim()).filter(Boolean)
            .map((text, index) => ({ index, text }));
    }

    async function runAiCheck() {
        const points = freeTextPoints();
        if (!points.length) { showAlert(T.write_points); return; }
        if (!(await ensureVisits())) return;

        const btn = $('#obs-ai-check');
        btn.disabled = true;
        $('#obs-ai-panel').innerHTML = `<div class="text-muted small"><span class="spinner-border spinner-border-sm"></span> ${esc(T.checking)}</div>`;
        let res;
        try {
            // Classifications belong to one visit: they are recorded on the first one.
            res = await api('POST', `/observation-visits/${S.visits[0].id}/ai-check`, { points });
        } catch (e) {
            res = { status: 'failed', message: e.message, results: [], classification_ids: [] };
        } finally {
            btn.disabled = false;
        }

        const ok = res.status === 'ok';
        S.ai = {
            status: res.status,
            message: ok ? null : (res.message || T.ai_unavailable),
            points: points.map((p, n) => {
                const r = ok ? (res.results || []).find((x) => x.index === p.index) : null;
                const sure = !!(r && r.catalog_item_id && catalogForMode().some((i) => i.id === r.catalog_item_id) && r.confidence >= 0.75);
                const category = categoriesForMode().find((c) => c.id === r?.category_id) || null;
                const point = {
                    index: p.index,
                    text: p.text,
                    ai_classification_id: (res.classification_ids || [])[n] ?? r?.ai_classification_id ?? null,
                    suggestedItemId: sure ? r.catalog_item_id : null,
                    useItem: sure,
                    category_id: category ? category.id : null,
                    kind: KINDS.includes(r?.kind) ? r.kind : (category?.default_kind || 'issue'),
                    risk_level_slug: riskBySlug(r?.risk_level) ? r.risk_level : '',
                    responsible_department_id: r?.responsible_department_id ?? null,
                };
                // With a category default risk the suggestion follows it (otherwise a reason would be required).
                const policy = riskById(category?.default_risk_level_id);
                if (policy) point.risk_level_slug = policy.slug;
                return point;
            }),
        };
        renderAi();
    }

    function aiPointRow(p) {
        const eff = effective(pointAsRow(p, true));
        const locked = p.useItem;
        const hideRouting = allMode();
        const head = p.suggestedItemId
            ? `<span class="text-success small">${esc(T.you_mean)} <b>${esc(labelOf(catalogById(p.suggestedItemId)))}</b></span>
               <label class="form-check-label small ms-2"><input type="checkbox" class="form-check-input" data-ai="useItem" ${p.useItem ? 'checked' : ''}> ${esc(T.use_item)}</label>`
            : (S.ai.status === 'ok' ? `<span class="badge text-bg-warning">${esc(T.suggested)}</span>` : '');
        return `
            <div class="border rounded p-2 mb-2" data-point="${p.index}">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-1"><span dir="auto" class="fw-semibold small">${esc(p.text)}</span> ${head}</div>
                <div class="row g-2">
                    <div class="col-sm-6 col-xl-3"><label class="form-label small text-muted mb-0">${esc(T.category)}</label>
                        <select class="form-select form-select-sm" data-ai="category_id" ${locked ? 'disabled' : ''}>${options(locked ? S.meta.categories : categoriesForMode(), locked ? eff.category?.id : p.category_id, (c) => c.id, nameOf, true)}</select></div>
                    <div class="col-sm-6 col-xl-3 ${hideRouting ? 'd-none' : ''}"><label class="form-label small text-muted mb-0">${esc(T.kind)}</label>
                        <select class="form-select form-select-sm" data-ai="kind" ${eff.kindLocked ? 'disabled' : ''}>${options(KINDS, eff.kind, (k) => k, (k) => T.kinds[k], false)}</select></div>
                    <div class="col-sm-6 col-xl-3 ${hideRouting ? 'd-none' : ''}"><label class="form-label small text-muted mb-0">${esc(T.risk)}</label>
                        <select class="form-select form-select-sm" data-ai="risk_level_slug" ${locked ? 'disabled' : ''}>${options(S.meta.risk_levels, eff.chosenRisk, (r) => r.slug, nameOf, true)}</select></div>
                    <div class="col-sm-6 col-xl-3 ${hideRouting ? 'd-none' : ''}"><label class="form-label small text-muted mb-0">${esc(T.responsible)}</label>
                        <select class="form-select form-select-sm" data-ai="responsible_department_id" ${eff.responsibleLocked ? 'disabled' : ''}>${options(S.meta.responsible_departments, eff.responsible, (d) => d.id, (d) => d.name, true)}</select></div>
                </div>
            </div>`;
    }

    function renderAi() {
        const panel = $('#obs-ai-panel');
        if (!S.ai) { panel.innerHTML = ''; return; }
        panel.innerHTML = `
            ${S.ai.message ? `<div class="alert alert-warning small py-2">${esc(S.ai.message)}</div>` : ''}
            ${S.ai.points.map(aiPointRow).join('')}
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-success btn-sm" data-ai-action="confirm"><i class="bi bi-check2-all"></i> ${esc(T.confirm_all)}</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-ai-action="cancel">${esc(T.cancel)}</button>
            </div>`;
    }

    /** The row a checked point becomes ("تأكيد الكل"). */
    function pointAsRow(p, preview) {
        const attrs = {
            source: p.useItem ? 'catalog' : 'free',
            catalog_item_id: p.useItem ? p.suggestedItemId : null,
            category_id: p.useItem ? null : p.category_id,
            kind: p.kind,
            risk_level_slug: p.useItem ? '' : p.risk_level_slug,
            responsible_department_id: p.useItem ? null : p.responsible_department_id,
            description: p.text,
            ai_classification_id: p.ai_classification_id,
        };
        return preview ? attrs : newRow(attrs);
    }

    async function addPlainRows() {
        const points = freeTextPoints();
        if (!points.length) { showAlert(T.write_points); return; }
        if (!(await ensureVisits())) return;
        points.forEach((p) => S.rows.push(newRow({ source: 'free', description: p.text })));
        $('#obs-free-text').value = '';
        S.ai = null;
        renderAi();
        renderRows();
        scheduleSave();
    }

    function wireAi() {
        $('#obs-ai-check').addEventListener('click', runAiCheck);
        $('#obs-add-plain').addEventListener('click', addPlainRows);
        const panel = $('#obs-ai-panel');
        panel.addEventListener('change', (e) => {
            const field = e.target.dataset.ai;
            const box = e.target.closest('[data-point]');
            if (!field || !box || !S.ai) return;
            const p = S.ai.points.find((x) => x.index === Number(box.dataset.point));
            if (field === 'useItem') {
                p.useItem = e.target.checked;
            } else if (field === 'category_id') {
                p.category_id = numOrNull(e.target.value);
                const c = categoryById(p.category_id);
                if (c) {
                    p.kind = c.default_kind || p.kind;
                    p.risk_level_slug = riskById(c.default_risk_level_id)?.slug || p.risk_level_slug;
                }
            } else if (field === 'responsible_department_id') {
                p.responsible_department_id = numOrNull(e.target.value);
            } else {
                p[field] = e.target.value;
            }
            renderAi();
        });
        panel.addEventListener('click', (e) => {
            const action = e.target.closest('[data-ai-action]')?.dataset.aiAction;
            if (!action || !S.ai) return;
            if (action === 'confirm') {
                S.ai.points.forEach((p) => S.rows.push(pointAsRow(p)));
                $('#obs-free-text').value = '';
                renderRows();
                scheduleSave();
            }
            S.ai = null;
            renderAi();
        });
    }

    // ===================================================================== 7. Draft rows
    function newRow(attrs) {
        return Object.assign({
            uid: ++uidSeq,
            source: 'free',
            catalog_item_id: null,
            category_id: null,
            kind: 'issue',
            risk_level_slug: '',
            risk_override_reason: '',
            responsible_department_id: null,
            description: '',
            product_or_area: '',
            extra_values: {},
            rating: '',
            photos: [],          // [{path, url}] uploaded once, reused for every visit
            ai_classification_id: null,
            warning: '',         // shown on the row (e.g. its catalog link was removed for "all branches")
            ids: {},             // visit id -> observation id, after the first save
            errors: {},          // field -> message, from the server's items.{i}.field
            uploading: 0,
            removed: false,
        }, attrs);
    }

    /**
     * Switching to "all branches" with rows / checked points whose catalog item or category is not an activity:
     * their link is removed visibly (warning on the row) instead of silently in the payload.
     */
    function stripNonActivity() {
        if (!allMode()) return;
        S.rows.forEach((row) => {
            const item = row.catalog_item_id ? catalogById(row.catalog_item_id) : null;
            const category = categoryById(row.category_id);
            if ((item && item.kind !== 'activity') || (!item && category && category.default_kind !== 'activity')) {
                row.description = row.description.trim() || labelOf(item) || nameOf(category);
                row.catalog_item_id = null;
                row.category_id = null;
                row.source = 'free';
                row.warning = T.link_removed;
                scheduleSave();
            }
        });
        (S.ai?.points || []).forEach((p) => {
            if (p.suggestedItemId && catalogById(p.suggestedItemId)?.kind !== 'activity') { p.suggestedItemId = null; p.useItem = false; }
            if (p.category_id && categoryById(p.category_id)?.default_kind !== 'activity') p.category_id = null;
        });
    }

    const isValid = (row) => !!row.catalog_item_id || row.description.trim() !== '';

    function extraFieldInput(row, f) {
        const v = row.extra_values[f.key] ?? '';
        const attrs = `class="form-control form-control-sm" data-extra="${esc(f.key)}" data-type="${esc(f.type)}" ${f.required ? 'required' : ''}`;
        let input;
        if (f.type === 'select') {
            const opts = (f.options || []).map((o) => (typeof o === 'object' ? { v: o.value ?? o.key, t: isEn ? (o.label_en || o.label_ar) : (o.label_ar || o.label) } : { v: o, t: o }));
            input = `<select ${attrs.replace('form-control', 'form-select')}>${options(opts, v, (o) => o.v, (o) => o.t, true)}</select>`;
        } else {
            input = `<input type="${f.type === 'number' ? 'number' : 'text'}" ${attrs} value="${esc(v)}" dir="auto">`;
        }
        return `<div class="col-sm-6 col-xl-3"><label class="form-label small text-muted mb-0">${esc(labelOf(f))}${f.required ? ' *' : ''}</label>${input}</div>`;
    }

    function renderRow(row) {
        const eff = effective(row);
        const hideRouting = allMode();
        const free = !row.catalog_item_id;
        const title = eff.item ? labelOf(eff.item) : (eff.category ? nameOf(eff.category) : T.free);
        const reasonBox = !hideRouting && eff.needsReason ? `
            <div class="col-12"><label class="form-label small text-muted mb-0">${esc(T.override_reason)} * <span class="text-muted">(${esc(T.override_hint)} ${esc(nameOf(eff.policyRisk))})</span></label>
                <input type="text" class="form-control form-control-sm" data-field="risk_override_reason" value="${esc(row.risk_override_reason)}" dir="auto"></div>` : '';

        return `
        <div class="border rounded p-2 mb-2" data-row="${row.uid}">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="badge ${free ? 'text-bg-secondary' : 'text-bg-primary'}">${esc(free ? T.free : T.catalog)}</span>
                <span class="fw-semibold small">${esc(title)}</span>
                <span class="badge text-bg-light border">${esc(T.kinds[eff.kind] || eff.kind)}</span>
                <button type="button" class="btn btn-sm btn-outline-danger ms-auto" data-action="delete" title="${esc(T.delete)}"><i class="bi bi-trash"></i></button>
            </div>
            ${row.warning ? `<div class="alert alert-warning small py-1 px-2 mb-2">${esc(row.warning)}</div>` : ''}
            <div class="row g-2">
                ${free ? `<div class="col-sm-6 col-xl-3"><label class="form-label small text-muted mb-0">${esc(T.category)}</label>
                    <select class="form-select form-select-sm" data-field="category_id">${options(categoriesForMode(), row.category_id, (c) => c.id, nameOf, true)}</select></div>` : ''}
                ${free && !hideRouting ? `<div class="col-sm-6 col-xl-3"><label class="form-label small text-muted mb-0">${esc(T.kind)}</label>
                    <select class="form-select form-select-sm" data-field="kind" ${eff.kindLocked ? `disabled title="${esc(T.from_category)}"` : ''}>${options(KINDS, eff.kind, (k) => k, (k) => T.kinds[k], false)}</select></div>` : ''}
                ${!hideRouting ? `<div class="col-sm-6 col-xl-3"><label class="form-label small text-muted mb-0">${esc(T.risk)}</label>
                    <select class="form-select form-select-sm" data-field="risk_level_slug">${options(S.meta.risk_levels, eff.chosenRisk, (r) => r.slug, nameOf, true)}</select></div>` : ''}
                ${free && !hideRouting ? `<div class="col-sm-6 col-xl-3"><label class="form-label small text-muted mb-0">${esc(T.responsible)}</label>
                    <select class="form-select form-select-sm" data-field="responsible_department_id" ${eff.responsibleLocked ? `disabled title="${esc(T.from_category)}"` : ''}>${options(S.meta.responsible_departments, eff.responsible, (d) => d.id, (d) => d.name, true)}</select></div>` : ''}
                ${eff.kind === 'assessment' ? `<div class="col-sm-6 col-xl-3"><label class="form-label small text-muted mb-0">${esc(T.rating)}</label>
                    <select class="form-select form-select-sm" data-field="rating">${options(RATINGS, row.rating, (r) => r, (r) => T.ratings[r], true)}</select></div>` : ''}
                ${reasonBox}
                <div class="col-md-8"><label class="form-label small text-muted mb-0">${esc(T.description)}</label>
                    <textarea class="form-control form-control-sm" rows="2" data-field="description" dir="auto">${esc(row.description)}</textarea></div>
                <div class="col-md-4"><label class="form-label small text-muted mb-0">${esc(T.product_or_area)}</label>
                    <input type="text" class="form-control form-control-sm" data-field="product_or_area" value="${esc(row.product_or_area)}" dir="auto"></div>
                ${(eff.item?.extra_fields || []).map((f) => extraFieldInput(row, f)).join('')}
                <div class="col-12"><label class="form-label small text-muted mb-0"><i class="bi bi-image"></i> ${esc(T.photos)}</label>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        ${row.photos.map((p) => `<a href="${esc(p.url)}" target="_blank" rel="noopener"><img src="${esc(p.url)}" alt="" class="rounded border" style="width:56px;height:56px;object-fit:cover"></a>`).join('')}
                        ${row.uploading ? `<span class="small text-muted"><span class="spinner-border spinner-border-sm"></span> ${esc(T.uploading)}</span>` : ''}
                        <input type="file" accept="image/*" multiple class="form-control form-control-sm w-auto" data-photos>
                    </div></div>
            </div>
            <div class="text-danger small mt-1" data-errors></div>
        </div>`;
    }

    function renderRows() {
        const box = $('#obs-rows');
        box.innerHTML = S.rows.length ? S.rows.map(renderRow).join('') : `<div class="text-muted small">${esc(T.no_rows)}</div>`;
        S.rows.forEach(renderRowErrors);
        $('#obs-rows-count').textContent = S.rows.length;
    }

    function rerenderRow(row) {
        const el = document.querySelector(`[data-row="${row.uid}"]`);
        if (!el) return renderRows();
        el.outerHTML = renderRow(row);
        renderRowErrors(row);
    }

    /** Server errors next to the row: the field is highlighted, every message is listed. */
    function renderRowErrors(row) {
        const el = document.querySelector(`[data-row="${row.uid}"]`);
        if (!el) return;
        el.querySelectorAll('.is-invalid').forEach((x) => x.classList.remove('is-invalid'));
        Object.keys(row.errors).forEach((field) => {
            const key = field.startsWith('extra_values.') ? `[data-extra="${field.slice(13)}"]` : `[data-field="${field}"]`;
            el.querySelector(key)?.classList.add('is-invalid');
        });
        el.querySelector('[data-errors]').innerHTML = Object.values(row.errors).map((m) => `<div>${esc(m)}</div>`).join('');
        el.classList.toggle('border-danger', Object.keys(row.errors).length > 0);
    }

    const rowOf = (el) => S.rows.find((r) => r.uid === Number(el.closest('[data-row]')?.dataset.row));

    function deleteRow(row) {
        row.removed = true;
        S.rows = S.rows.filter((r) => r !== row);
        renderRows();
        // Never race a running upsert that still carries this row's id: delete after it.
        S.pendingDeletes.push((S.savePromise || Promise.resolve()).catch(() => {}).then(() => removeServerIds(row)));
    }

    /**
     * DELETE /observations/{id} for every visit the row was saved to (draft only). An id is forgotten only once its
     * DELETE succeeded (404 = already gone); a row that could not be deleted stays in S.ghosts and blocks submit.
     */
    async function removeServerIds(row) {
        for (const [visitId, id] of Object.entries(row.ids)) {
            try {
                await api('DELETE', `/observations/${id}`);
                delete row.ids[visitId];
            } catch (e) {
                if (e.status === 404) delete row.ids[visitId];
                else showAlert(`${T.delete_failed} ${e.message}`);
            }
        }
        if (Object.keys(row.ids).length) S.ghosts.add(row); else S.ghosts.delete(row);
    }

    async function uploadPhotos(row, files) {
        if (!files.length || !(await ensureVisits())) return;
        row.uploading += files.length;
        rerenderRow(row);
        for (const file of files) {
            const form = new FormData();
            form.append('photo', file);
            form.append('kind', 'before');
            try {
                // Uploaded once (to the first visit); the path is reused for every visit in desk-multi mode.
                const res = await api('POST', `/observation-visits/${S.visits[0].id}/photos`, form);
                row.photos.push({ path: res.path, url: res.url });
                delete row.errors.photos;
            } catch (e) {
                row.errors.photos = Object.values(e.errors).flat()[0] || e.message;
            } finally {
                row.uploading -= 1;
            }
        }
        if (!row.removed) rerenderRow(row);
        scheduleSave();
    }

    function wireRows() {
        const box = $('#obs-rows');
        box.addEventListener('input', (e) => {
            const row = rowOf(e.target);
            if (!row) return;
            const field = e.target.dataset.field;
            if (field === 'description' || field === 'product_or_area' || field === 'risk_override_reason') {
                row[field] = e.target.value;
                scheduleSave();
            } else if (e.target.dataset.extra && e.target.tagName !== 'SELECT') {
                const v = e.target.value;
                row.extra_values[e.target.dataset.extra] = e.target.dataset.type === 'number' && v !== '' ? Number(v) : v;
                scheduleSave();
            }
        });
        box.addEventListener('change', (e) => {
            const row = rowOf(e.target);
            if (!row) return;
            if (e.target.matches('[data-photos]')) {
                uploadPhotos(row, Array.from(e.target.files));
                return;
            }
            const field = e.target.dataset.field;
            if (e.target.dataset.extra && e.target.tagName === 'SELECT') {
                row.extra_values[e.target.dataset.extra] = e.target.value;
            } else if (field === 'category_id') {
                row.category_id = numOrNull(e.target.value);
                row.risk_level_slug = ''; // back to the new category's default risk
                row.risk_override_reason = '';
            } else if (field === 'responsible_department_id') {
                row.responsible_department_id = numOrNull(e.target.value);
            } else if (field === 'kind' || field === 'rating' || field === 'risk_level_slug') {
                row[field] = e.target.value;
            } else {
                return;
            }
            rerenderRow(row);
            scheduleSave();
        });
        box.addEventListener('click', (e) => {
            if (e.target.closest('[data-action="delete"]')) {
                const row = rowOf(e.target);
                if (row) deleteRow(row);
            }
        });
    }

    // ===================================================================== 8. Auto-save (debounced upsert)
    /** One upsert item for one visit. Only the routing the server will not override is sent. */
    function payload(row, visit) {
        const eff = effective(row);
        const item = { description: row.description.trim() || null };
        if (row.ids[visit.id]) item.id = row.ids[visit.id];

        if (allMode()) {
            // No branch -> no ticket: every row is an activity. The picker only offers activity items / categories
            // (stripNonActivity removes any other link visibly), so the server keeps kind=activity by precedence.
            item.kind = 'activity';
            if (row.catalog_item_id) item.catalog_item_id = row.catalog_item_id;
            else if (row.category_id) item.category_id = row.category_id;
        } else {
            item.kind = eff.kind;
            if (row.catalog_item_id) item.catalog_item_id = row.catalog_item_id;
            else if (row.category_id) item.category_id = row.category_id;
            if (eff.chosenRisk) item.risk_level_slug = eff.chosenRisk;
            if (eff.needsReason) item.risk_override_reason = row.risk_override_reason.trim() || null;
            if (!row.catalog_item_id && !eff.responsibleLocked && row.responsible_department_id) {
                item.responsible_department_id = row.responsible_department_id;
            }
            if (eff.kind === 'assessment' && row.rating) item.rating = row.rating;
        }

        if (row.product_or_area.trim()) item.product_or_area = row.product_or_area.trim();
        const extras = Object.fromEntries(Object.entries(row.extra_values).filter(([, v]) => v !== '' && v !== null));
        if (Object.keys(extras).length) item.extra_values = extras;
        if (row.photos.length) { item.photos = row.photos.map((p) => p.path); item.photo_source = 'gallery'; }
        // The classification rows belong to the visit the check ran on (the first one).
        if (row.ai_classification_id && visit === S.visits[0]) item.ai_classification_id = row.ai_classification_id;
        return item;
    }

    function scheduleSave() {
        if (!S.visits.length || S.done) return;
        S.dirty = true;
        S.editSeq += 1;
        clearTimeout(S.saveTimer);
        S.saveTimer = setTimeout(() => { S.saveTimer = null; saveAll(); }, 800);
    }

    /** Serialised: a change during a running save triggers exactly one more save after it. Resolves true when clean. */
    function saveAll() {
        if (S.savePromise) { S.savePending = true; return S.savePromise; }
        clearTimeout(S.retryTimer);
        S.retryTimer = null;
        S.savePromise = (async () => {
            let result;
            let seq;
            setIndicator('saving');
            do {
                S.savePending = false;
                seq = S.editSeq;
                result = await saveOnce();
            } while (S.savePending);
            if (result.ok) {
                S.retryStep = 0;
                if (S.editSeq === seq) S.dirty = false; // an edit made during the save keeps it dirty (its timer saves it)
            } else if (result.transient && S.dirty) {
                scheduleRetry();
            }
            setIndicator(result.ok ? 'saved' : (result.transient ? 'retrying' : 'failed'));
            return result.ok;
        })().finally(() => { S.savePromise = null; });
        return S.savePromise;
    }

    /** Network / 5xx failures retry on their own while there are unsaved edits: 2 s, 5 s, then every 15 s. */
    function scheduleRetry() {
        const delay = [2000, 5000, 15000][Math.min(S.retryStep, 2)];
        S.retryStep += 1;
        clearTimeout(S.retryTimer);
        S.retryTimer = setTimeout(() => { S.retryTimer = null; if (S.dirty && !S.done) saveAll(); }, delay);
    }

    /** @returns {{ok: boolean, transient: boolean}} transient = worth retrying on its own (network / 5xx). */
    async function saveOnce() {
        let ok = true;
        let transient = false;
        if (S.targets.some((t) => !t.visit) && !(await ensureVisits())) {
            ok = false; // the missing visits stay listed in the visit status until created
            transient = S.targets.some((t) => !t.visit && t.transient);
        }

        // A row already on the server is always sent, even emptied: the server's 422 then shows on the row
        // instead of the old text silently staying (and being submitted).
        const rows = S.rows.filter((r) => isValid(r) || Object.keys(r.ids).length);
        if (!rows.length) return { ok, transient };
        const errors = new Map(rows.map((r) => [r, {}]));

        for (const visit of S.visits.filter((v) => !v.submitted)) {
            try {
                const res = await api('POST', `/observation-visits/${visit.id}/observations`, { items: rows.map((r) => payload(r, visit)) });
                res.data.forEach((o, i) => { rows[i].ids[visit.id] = o.id; });
            } catch (e) {
                ok = false;
                if (e.status !== 422) { transient = isTransient(e); showAlert(e.message); break; }
                collectErrors(e.errors, (i) => rows[i], errors);
            }
        }

        rows.forEach((r) => {
            const uploadError = r.errors.photos; // set by uploadPhotos, cleared by its next success
            r.errors = errors.get(r);
            if (uploadError && !r.errors.photos) r.errors.photos = uploadError;
            renderRowErrors(r); // a row deleted meanwhile is gone from the page; deleteRow deletes it after this save
        });
        if (ok && !S.ghosts.size) showAlert('');
        return { ok, transient };
    }

    /** `items.{i}.field` -> the row at position i; anything else goes to the page alert. */
    function collectErrors(errors, rowAt, into) {
        const general = [];
        Object.entries(errors).forEach(([key, messages]) => {
            const m = /^items\.(\d+)\.(.+)$/.exec(key);
            const row = m ? rowAt(Number(m[1])) : null;
            if (row && into.has(row)) into.get(row)[m[2]] = messages[0];
            else general.push(messages[0]);
        });
        if (general.length) showAlert(general.join(' '));
    }

    // ===================================================================== 9. Submit
    async function submitVisit() {
        const btn = $('#obs-submit');
        if (!(await ensureVisits())) return;
        clearTimeout(S.saveTimer);
        S.saveTimer = null;
        // Every delete must have reached the server: a row still there would be submitted (and shift the error index).
        await Promise.all(S.pendingDeletes);
        S.pendingDeletes = [];
        for (const ghost of Array.from(S.ghosts)) await removeServerIds(ghost);
        if (S.ghosts.size) { showAlert(T.delete_blocked); return; }
        // Pre-check: every visit must save cleanly before any is submitted.
        if (!(await saveAll())) { showAlert(T.fix_errors); return; }
        if (!window.confirm(T.confirm_submit)) return;

        btn.disabled = true;
        const label = btn.innerHTML;
        btn.innerHTML = `<span class="spinner-border spinner-border-sm"></span> ${esc(T.submitting)}`;
        let failed = false;
        // Sequential, stopping at the first failure: the rows are the same on every visit, so a 422 on one would
        // repeat on the next, and the visits never drift apart silently (the per-visit status shows where each is).
        for (const visit of S.visits.filter((v) => !v.submitted)) {
            const target = S.targets.find((t) => t.visit === visit);
            try {
                visit.result = await api('POST', `/observation-visits/${visit.id}/submit`);
                visit.submitted = true;
                target.error = '';
            } catch (e) {
                failed = true;
                target.error = e.status === 422 ? T.fix_errors : e.message;
                if (e.status === 422) {
                    // Submit errors use the row's position in the visit's observations ordered by id.
                    const ordered = S.rows.filter((r) => r.ids[visit.id]).sort((a, b) => a.ids[visit.id] - b.ids[visit.id]);
                    const errors = new Map(S.rows.map((r) => [r, r.errors]));
                    collectErrors(e.errors, (i) => ordered[i], errors);
                    S.rows.forEach(renderRowErrors);
                } else {
                    showAlert(e.message);
                }
                break;
            }
        }
        renderVisitStatus();
        btn.disabled = false;
        btn.innerHTML = label;

        if (failed) { if ($('#obs-alert').classList.contains('d-none')) showAlert(T.fix_errors); return; }
        S.done = true;
        renderSuccess();
    }

    function renderSuccess() {
        const u = CFG.urls;
        const blocks = S.visits.map((v) => {
            const tickets = v.result?.created_tickets || [];
            const rows = S.rows.filter((r) => r.ids[v.id]);
            return `
                <div class="card p-3 mb-3">
                    <h6 class="fw-bold">${esc(v.code)} · ${esc(v.branch_name || T.all_branches)}</h6>
                    <div class="small text-muted mb-1">${esc(T.created_tickets)}</div>
                    ${tickets.length ? `<ul class="small">${tickets.map((t) => `<li><a href="${esc(u.tickets)}/${t.id}">${esc(t.reference)}</a> — <span dir="auto">${esc(t.title || '')}</span>${t.department ? ` · ${esc(t.department.name)}` : ''}</li>`).join('')}</ul>`
                        : `<div class="small mb-2">${esc(T.no_tickets)}</div>`}
                    <div class="small text-muted mb-1">${esc(T.observations)}</div>
                    <ul class="small mb-0">${rows.map((r) => `<li><a href="${esc(u.observations)}/${r.ids[v.id]}" dir="auto">${esc(r.description || labelOf(catalogById(r.catalog_item_id)))}</a></li>`).join('')}</ul>
                </div>`;
        }).join('');

        $('#obs-editor').classList.add('d-none');
        showAlert('');
        const box = $('#obs-success');
        box.innerHTML = `
            <div class="alert alert-success"><i class="bi bi-check-circle"></i> ${esc(T.submitted)}</div>
            ${blocks}
            <div class="d-flex gap-2">
                <a href="${esc(u.register)}" class="btn btn-outline-primary">${esc(T.register)}</a>
                <a href="${esc(u.observations)}/new" class="btn btn-primary">${esc(T.new_visit)}</a>
            </div>`;
        box.classList.remove('d-none');
    }

    // ===================================================================== 10. Boot
    document.addEventListener('DOMContentLoaded', () => {
        renderSetup();
        renderCatalog();
        // A global role without a department of its own: start on the first reporting department.
        if (!S.departmentId && S.meta.reporting_departments.length) onDepartmentChange(S.meta.reporting_departments[0].id);
        renderRows();
        wireSetup();
        wireCatalog();
        wireAi();
        wireRows();
        $('#obs-submit').addEventListener('click', submitVisit);
        window.addEventListener('beforeunload', (e) => {
            if (!S.done && (S.dirty || S.saveTimer || S.savePromise || S.ghosts.size)) { e.preventDefault(); e.returnValue = ''; }
        });
    });
})();
