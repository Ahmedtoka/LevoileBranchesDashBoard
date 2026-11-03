{{-- The list pages (CCTV, finance): filter bar, summary cells, the table with a fixed header, the pager. --}}
@push('head')
<style>
    .cv-filters .cv-bar-row { gap: .6rem 1rem; }
    .cv-weeks-inline { margin: 0; padding: 0; border: 0; }
    .cv-weeks-inline a { min-inline-size: 0; padding: .3rem .7rem; }
    .cv-range summary { cursor: pointer; font-size: .85rem; color: var(--brand); list-style: none; }
    .cv-range summary::-webkit-details-marker { display: none; }
    .cv-range label { display: flex; flex-direction: column; gap: .15rem; color: var(--ink-2); }
    .cv-fields { display: grid; grid-template-columns: repeat(auto-fill, minmax(10.5rem, 1fr)); gap: .55rem .7rem; margin-top: .8rem; padding-top: .8rem; border-top: 1px solid var(--line); }
    .cv-fields > label { display: flex; flex-direction: column; gap: .2rem; font-size: .75rem; color: var(--ink-2); font-weight: 600; margin: 0; }
    .cv-field-wide { grid-column: span 2; }
    .cv-search { position: relative; }
    .cv-search i { position: absolute; inset-inline-start: .55rem; top: 50%; transform: translateY(-50%); color: var(--muted); font-size: .8rem; }
    .cv-search input { padding-inline-start: 1.8rem; }
    .cv-chips-row { display: flex; flex-wrap: wrap; gap: .35rem; align-items: center; margin-top: .7rem; }
    .cv-chip { display: inline-flex; align-items: center; gap: .15rem; background: var(--brand-soft); color: var(--brand); border-radius: 8px; padding: .15rem .3rem .15rem .55rem; font-size: .8rem; font-weight: 600; }
    .cv-chip:hover { background: #ecd2e3; }
    .cv-chip-clear { font-size: .8rem; color: var(--muted); margin-inline-start: .3rem; }
    .cv-chip-clear:hover { color: var(--brand); }

    .cv-field-note { font-size: .7rem; font-weight: 400; color: var(--muted); }
    .cv-sum { background: var(--surface); border-radius: 14px; margin-bottom: 1rem; box-shadow: 0 1px 2px rgba(74,15,51,.05); overflow: hidden; }
    .cv-sum-stats { display: grid; grid-template-columns: 1.4fr repeat(6, 1fr); }
    .cv-stat { position: relative; display: flex; flex-direction: column; justify-content: center; gap: .2rem; padding: .85rem 1rem; color: var(--ink); border-inline-start: 1px solid var(--line); }
    .cv-stat:first-child { border-inline-start: 0; }
    .cv-stat span { font-size: .74rem; color: var(--muted); }
    .cv-stat b { font-size: 1.45rem; font-weight: 800; line-height: 1.1; letter-spacing: -.02em; }
    .cv-stat b small { font-size: .75rem; font-weight: 600; color: var(--ink-2); letter-spacing: 0; }
    .cv-stat > i { position: absolute; inset-inline-end: .7rem; bottom: .9rem; font-size: .75rem; color: var(--muted); transition: transform .15s, color .15s; }
    a.cv-stat:hover { background: #fbf6f9; }
    a.cv-stat:hover > i { color: var(--brand); transform: translateX(-2px); }
    .cv-stat-main { background: var(--brand); color: #fff; }
    .cv-stat-main span { color: #f3dbe9; }
    .cv-stat-main b { font-size: 2.1rem; }
    .cv-split { padding: .85rem 1rem 1rem; border-top: 1px solid var(--line); }
    .cv-split-bar { display: flex; gap: 2px; block-size: 10px; border-radius: 6px; overflow: hidden; }
    .cv-split-bar a { display: block; min-inline-size: 4px; transition: filter .15s; }
    .cv-split-bar a:hover { filter: brightness(.88); }
    .cv-split-legend { list-style: none; margin: .7rem 0 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(13rem, 1fr)); gap: .25rem .8rem; font-size: .82rem; }
    .cv-split-legend a { display: grid; grid-template-columns: .7rem 1fr auto 2.6rem; align-items: center; gap: .4rem; padding: .3rem .45rem; border-radius: 8px; color: var(--ink-2); }
    .cv-split-legend a:hover, .cv-split-legend a.on { background: #f8f4f7; color: var(--ink); }
    .cv-split-legend span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cv-split-legend b { color: var(--ink); }
    .cv-split-legend small { color: var(--muted); text-align: end; }
    @media (max-width: 1199.98px) { .cv-sum-stats { grid-template-columns: repeat(4, 1fr); } .cv-stat:nth-child(5) { border-inline-start: 0; } .cv-stat:nth-child(n+5) { border-top: 1px solid var(--line); } }
    @media (max-width: 575.98px) { .cv-sum-stats { grid-template-columns: repeat(2, 1fr); } }

    /* Header stays; the rows scroll under it inside the frame. */
    .cv-table-wrap { background: var(--surface); border-radius: 14px; box-shadow: 0 1px 2px rgba(74,15,51,.05); overflow: auto; max-block-size: calc(100vh - 9rem); min-block-size: 18rem; scrollbar-width: thin; scrollbar-color: #d8c9d2 transparent; }
    .cv-table { inline-size: 100%; border-collapse: separate; border-spacing: 0; font-size: .85rem; }
    .cv-table thead th { position: sticky; top: 0; z-index: 2; background: #843c0c; color: #fff; font-weight: 600; font-size: .78rem; padding: .6rem .7rem; text-align: start; white-space: nowrap; }
    .cv-table tbody td { padding: .55rem .7rem; border-bottom: 1px solid var(--line); vertical-align: top; }
    .cv-table tbody tr:hover td { background: #fbf6f9; }
    .cv-td-date { white-space: nowrap; }
    .cv-td-date b { display: block; font-size: .95rem; }
    .cv-td-date small, .cv-td-emp small { display: block; color: var(--muted); font-size: .72rem; }
    .cv-td-emp a { color: var(--ink); font-weight: 600; }
    .cv-td-emp a:hover { color: var(--brand); }
    .cv-td-vio { min-inline-size: 16rem; }
    .cv-td-vio b { font-weight: 600; }
    .cv-td-type { display: flex; align-items: center; gap: .35rem; font-size: .72rem; color: var(--muted); }
    .cv-td-desc { display: block; font-size: .78rem; color: var(--ink-2); margin-top: .15rem; }
    .cv-mins { display: inline-block; margin-inline-start: .35rem; background: #fdf3dc; color: #7a5300; font-size: .72rem; font-weight: 700; padding: 0 .4rem; border-radius: 6px; }
    .cv-td-reply { font-size: .78rem; color: var(--ink-2); max-inline-size: 18rem; }
    .cv-ref { font-size: .75rem; color: var(--muted); white-space: nowrap; }
    .cv-ref:hover { color: var(--brand); }
    .cv-td-empty { text-align: center; padding: 3rem 1rem !important; }
    .cv-td-empty i { font-size: 1.8rem; color: var(--muted); display: block; margin-bottom: .4rem; }
    .cv-pager { display: flex; flex-wrap: wrap; align-items: center; gap: .6rem; margin-top: .8rem; }
    .cv-pager .pagination { margin: 0; --bs-pagination-active-bg: var(--brand); --bs-pagination-active-border-color: var(--brand); --bs-pagination-color: var(--brand); --bs-pagination-font-size: .85rem; }
    .cv-pager nav > div:first-child { display: none !important; } /* Bootstrap 5 view repeats "showing x of y" */
    .cv-pager select { inline-size: auto; }
    @media (max-width: 575.98px) { .cv-field-wide { grid-column: auto; } .cv-table-wrap { max-block-size: none; } }
</style>
@endpush
