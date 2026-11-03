@push('head')
<style>
    /* CCTV pages. Data colours come from the validated categorical palette; UI chrome stays on the Le Voile magenta. */
    .cv { --ink: #1f1a1d; --ink-2: #5b5258; --muted: #8a8087; --line: #ebe4e8; --surface: #fff; --brand: #9c1e6e; --brand-soft: #f6e9f1;
          --up: #c23434; --up-bg: #fbeaea; --down: #006300; --down-bg: #e6f3e6; color: var(--ink); font-variant-numeric: tabular-nums; }
    .cv ::selection { background: #f0d3e5; }
    .cv a { text-decoration: none; }
    .cv a:focus-visible, .cv button:focus-visible, .cv select:focus-visible, .cv input:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }

    /* Filter bar */
    .cv-bar { background: var(--surface); border-radius: 14px; padding: .9rem 1rem .8rem; margin-bottom: 1rem; box-shadow: 0 1px 2px rgba(74,15,51,.06), 0 6px 18px -12px rgba(74,15,51,.25); }
    .cv-bar-row { display: flex; flex-wrap: wrap; gap: .75rem 1.25rem; align-items: center; }
    .cv-step { display: flex; align-items: center; gap: .25rem; }
    .cv-arrow { inline-size: 2.1rem; block-size: 2.1rem; display: grid; place-items: center; border-radius: 10px; color: var(--ink-2); border: 1px solid var(--line); transition: background .15s, color .15s; }
    .cv-arrow:hover { background: var(--brand-soft); color: var(--brand); }
    .cv-select { appearance: auto; border: 1px solid var(--line); border-radius: 10px; padding: .4rem .7rem; font-weight: 700; font-size: 1rem; color: var(--ink); background: #fff; min-inline-size: 13rem; }
    .cv-month { font-weight: 800; font-size: 1.15rem; min-inline-size: 8.5rem; text-align: center; letter-spacing: -.01em; }
    .cv-actions { margin-inline-start: auto; display: flex; gap: .4rem; }
    .cv-weeks { display: flex; flex-wrap: wrap; gap: .4rem; margin-top: .8rem; padding-top: .8rem; border-top: 1px solid var(--line); }
    .cv-weeks a { display: flex; flex-direction: column; padding: .35rem .85rem; border-radius: 10px; color: var(--ink-2); border: 1px solid var(--line); line-height: 1.25; min-inline-size: 6.5rem; transition: background .15s, border-color .15s; }
    .cv-weeks a b { font-size: .88rem; color: var(--ink); }
    .cv-weeks a small { font-size: .74rem; color: var(--muted); }
    .cv-weeks a:hover { border-color: var(--brand); }
    .cv-weeks a.on { background: var(--brand); border-color: var(--brand); }
    .cv-weeks a.on b, .cv-weeks a.on small { color: #fff; }
    .cv-weeks a.is-future { opacity: .55; }

    /* Headline */
    .cv-head { display: grid; grid-template-columns: minmax(15rem, 19rem) 1fr; gap: 1rem; margin-bottom: 1rem; }
    .cv-total { background: var(--brand); color: #fff; border-radius: 14px; padding: 1.1rem 1.2rem; display: flex; flex-direction: column; gap: .2rem; }
    .cv-total-label { font-size: .9rem; color: #f3dbe9; }
    .cv-total-n { font-size: 3.4rem; font-weight: 800; line-height: 1; letter-spacing: -.03em; }
    .cv-total-sub { display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; font-size: .82rem; color: #f3dbe9; }
    .cv-total .cv-delta { background: rgba(255,255,255,.16); color: #fff; }
    .cv-total-facts { display: grid; grid-template-columns: 1fr 1fr; gap: .55rem .8rem; margin: auto 0 0; padding-top: .9rem; border-top: 1px solid rgba(255,255,255,.2); }
    .cv-total-facts dt { font-weight: 400; font-size: .72rem; color: #f3dbe9; }
    .cv-total-facts dd { margin: 0; font-weight: 700; font-size: .92rem; }
    .cv-types { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .6rem; }
    .cv-type { background: var(--surface); border-radius: 12px; padding: .75rem .85rem .7rem; color: var(--ink); display: flex; flex-direction: column; gap: .3rem; border: 1px solid transparent; transition: border-color .15s, transform .15s; }
    .cv-type:hover { border-color: var(--c); transform: translateY(-1px); }
    .cv-type-name { font-size: .82rem; color: var(--ink-2); display: flex; align-items: center; gap: .4rem; line-height: 1.3; min-block-size: 2.1em; }
    .cv-type-n { font-size: 1.9rem; font-weight: 800; line-height: 1; letter-spacing: -.02em; }
    .cv-type-bar { block-size: 6px; background: #f1edf0; border-radius: 4px; overflow: hidden; }
    .cv-type-bar i { display: block; block-size: 100%; background: var(--c); border-radius: 4px; }
    .cv-type-foot { display: flex; justify-content: space-between; align-items: center; font-size: .74rem; color: var(--muted); }
    .cv-dot { display: inline-block; inline-size: .6rem; block-size: .6rem; border-radius: 50%; background: var(--c); flex: none; }

    /* Change badge */
    .cv-delta { display: inline-flex; align-items: center; gap: .1rem; font-size: .76rem; font-weight: 700; padding: .05rem .4rem; border-radius: 6px; white-space: nowrap; }
    .cv-delta i { font-size: 1rem; line-height: 1; }
    .cv-delta.is-up { color: var(--up); background: var(--up-bg); }
    .cv-delta.is-down { color: var(--down); background: var(--down-bg); }
    .cv-delta.is-flat { color: var(--muted); background: #f3f1f2; }

    /* Panels */
    .cv-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
    .cv-panel { background: var(--surface); border-radius: 14px; padding: 1rem 1.1rem; box-shadow: 0 1px 2px rgba(74,15,51,.05); margin-bottom: 1rem; }
    .cv-grid > .cv-panel { margin-bottom: 0; }
    .cv-h { font-size: 1rem; font-weight: 800; margin: 0 0 .8rem; letter-spacing: -.01em; }
    .cv-h-sm { font-size: .85rem; margin-top: 1.1rem; color: var(--ink-2); }
    .cv-rank { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .55rem; }
    .cv-rank li { display: grid; grid-template-columns: 1.6rem 1fr auto 4.2rem; align-items: center; gap: .6rem; }
    .cv-rank-i { inline-size: 1.6rem; block-size: 1.6rem; border-radius: 8px; background: #f3f1f2; display: grid; place-items: center; font-size: .78rem; font-weight: 700; color: var(--ink-2); }
    .cv-rank li:first-child .cv-rank-i { background: var(--brand); color: #fff; }
    .cv-rank-body { display: flex; flex-direction: column; gap: .25rem; min-inline-size: 0; color: var(--ink); }
    .cv-rank-name { font-weight: 600; font-size: .9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cv-rank-name small { font-weight: 400; color: var(--muted); margin-inline-start: .3rem; }
    .cv-rank-bar { block-size: 8px; background: #f1edf0; border-radius: 4px; overflow: hidden; }
    .cv-rank-bar i { display: block; block-size: 100%; background: #2a78d6; border-radius: 4px; }
    .cv-rank-note { font-size: .74rem; color: var(--ink-2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cv-rank-n { font-weight: 800; font-size: 1.05rem; text-align: end; }
    .cv-rank-n small { font-weight: 400; font-size: .72rem; color: var(--muted); }
    .cv-rank li > .cv-delta { justify-self: end; }
    a.cv-rank-body:hover .cv-rank-name { color: var(--brand); }
    .cv-rank-people { gap: .5rem; }
    .cv-chips { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: .35rem; }
    .cv-chips li { background: #f6f3f5; border-radius: 8px; padding: .2rem .55rem; font-size: .8rem; color: var(--ink-2); }
    .cv-chips b { color: var(--ink); margin-inline-start: .2rem; }
    .cv-chart { position: relative; block-size: 260px; }
    .cv-weekbox-body { display: grid; grid-template-columns: minmax(17rem, 24rem) 1fr; gap: 1.4rem; align-items: stretch; }
    .cv-weekbox-chart { position: relative; min-block-size: 300px; }
    .cv-legend { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .15rem; }
    .cv-legend li { display: grid; grid-template-columns: .9rem 1fr auto 2.6rem; grid-template-rows: auto auto; align-items: center; column-gap: .45rem; padding: .35rem .45rem; border-radius: 9px; cursor: default; transition: background .15s, opacity .15s; }
    .cv-legend li:hover, .cv-legend li:focus { background: #f8f4f7; outline: none; }
    .cv-legend li.is-dim { opacity: .45; }
    .cv-legend-name { font-size: .85rem; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cv-legend li b { font-size: .95rem; text-align: end; }
    .cv-legend-pct { font-size: .74rem; color: var(--muted); text-align: end; }
    .cv-legend-bar { grid-column: 2 / -1; block-size: 4px; background: #f1edf0; border-radius: 3px; overflow: hidden; margin-top: .2rem; }
    .cv-legend-bar i { display: block; block-size: 100%; border-radius: 3px; }
    .cv-weekcells { display: grid; grid-template-columns: repeat(4, 1fr); gap: .35rem; margin-top: .8rem; padding-top: .8rem; border-top: 1px solid var(--line); }
    .cv-weekcells a { display: flex; flex-direction: column; align-items: center; gap: .15rem; padding: .45rem .2rem; border-radius: 10px; background: #faf7f9; color: var(--ink); border: 1px solid transparent; }
    .cv-weekcells a:hover { border-color: var(--brand); }
    .cv-weekcells a.on { border-color: var(--brand); background: var(--brand-soft); }
    .cv-weekcells a.is-future { opacity: .5; }
    .cv-weekcells span:first-child { font-size: .72rem; color: var(--muted); }
    .cv-weekcells b { font-size: 1.15rem; line-height: 1.1; }
    .cv-chart-days { block-size: 220px; }
    @media (max-width: 991.98px) { .cv-weekbox-body { grid-template-columns: 1fr; } }
    .cv-tabs { padding: .5rem .7rem 0; border-bottom-color: var(--line); }
    .cv-tabs .nav-link { color: var(--ink-2); font-weight: 600; border: 0; border-bottom: 2px solid transparent; }
    .cv-tabs .nav-link span { font-size: .72rem; color: var(--muted); font-weight: 400; margin-inline-start: .2rem; }
    .cv-tabs .nav-link.active { color: var(--brand); border-bottom-color: var(--brand); background: none; }
    .cv-empty { background: var(--surface); border-radius: 14px; padding: 3rem 1rem; text-align: center; }
    .cv-empty > i { font-size: 2.2rem; color: var(--muted); display: block; margin-bottom: .6rem; }

    @media (max-width: 991.98px) { .cv-head, .cv-grid { grid-template-columns: 1fr; } }
    @media (max-width: 575.98px) {
        .cv-types { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .cv-actions { margin-inline-start: 0; inline-size: 100%; }
        .cv-select { min-inline-size: 0; inline-size: 100%; }
        .cv-rank li { grid-template-columns: 1.6rem 1fr auto; }
        .cv-rank li > .cv-delta { grid-column: 2 / -1; justify-self: start; }
    }
    @media print { .cv-bar .cv-arrow, .cv-tabs { display: none !important; } .tab-pane { display: block !important; opacity: 1 !important; } }

    /* The weekly sheet's pivot look: brown header and total, thin grid, three-colour scale on the counts. */
    .cctv-pivot { border-collapse: collapse; width: 100%; font-size: .85rem; background: #fff; font-variant-numeric: tabular-nums; }
    .cctv-pivot th, .cctv-pivot td { border: 1px solid #d9cfc7; padding: .35rem .5rem; text-align: center; vertical-align: middle; }
    .cctv-pivot thead th { background: #843c0c; color: #fff; font-weight: 600; font-size: .78rem; line-height: 1.3; }
    .cctv-pivot thead th .cv-dot { box-shadow: 0 0 0 2px rgba(255,255,255,.85); margin-inline-end: .15rem; }
    .cctv-pivot td.name { text-align: start; font-weight: 600; white-space: nowrap; }
    .cctv-pivot td.num { color: #5b5258; }
    .cctv-pivot tr.total td { background: #843c0c; color: #fff; font-weight: 700; }
    .cctv-pivot tr.total .cv-delta { background: rgba(255,255,255,.18); color: #fff; }
    .cctv-pivot tr.share td { background: #f6eee8; color: #5b5258; font-size: .78rem; }
    .cctv-pivot tr.branch td { background: #f6eee8; font-weight: 700; }
    .cctv-pivot tr.person td.name { font-weight: 400; padding-inline-start: 1.6rem; }
    .cctv-pivot tr.is-future td { opacity: .5; }
    .cctv-pivot td.gt { font-weight: 700; }
    .cctv-pivot a { color: inherit; text-decoration: none; display: block; }
    .cctv-pivot a:hover { text-decoration: underline; }
    .cctv-pivot .muted { color: #9a8f88; font-weight: 400; }
</style>
@endpush
