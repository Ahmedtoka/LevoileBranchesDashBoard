"""Copies the evidence files the constitution page links to into storage/app/handover-evidence (git-ignored)
and writes a readable HTML preview for each Word, Excel and PowerPoint file. Stdlib only.

Run with: python -I docs/handover/build-evidence-attachments.py   (from BranchesDashboard)
"""
import html
import posixpath
import re
import shutil
import sys
import zipfile
from datetime import date, timedelta
from pathlib import Path
from xml.etree import ElementTree as E

ROOT = Path(__file__).resolve().parents[2]
OLD = ROOT.parent / 'OldData'
OUT = ROOT / 'storage' / 'app' / 'handover-evidence'
W1 = OLD / 'تقارير زيارات الإدارات الإسبوع الأول'
W2 = OLD / 'تقارير زيارات الإدارات الإسبوع الثاني'
W3 = OLD / 'تقارير زيارات الإسبوع الثالث'

FILES = {
    'store-visit-1.jpeg': W2 / 'IT/store visit.jpeg',
    'store-visit-2.jpeg': W2 / 'IT/store visit 2.jpeg',
    'store-visit-3.jpeg': W2 / 'IT/store visit 3.jpeg',
    'lp-sop.docx': W1 / 'LP/SOP.docx',
    'lp-daily-closing.pptx': W1 / 'LP/تعليمات استخدام التقفيل اليومي.pptx',
    'cctv-w1.xlsx': W1 / 'CC TV/Action Plan_ CCTV.XLSX',
    'cctv-w2.xlsx': W2 / 'CCTV/Action Plan - Second Week.XLSX',
    'cctv-w3.xlsx': W3 / 'CC TV/Action Plan - Third Week.XLSX',
    'cctv-pivot-w1.png': W1 / 'CC TV/Capture.PNG',
    'lp-training-w2.xlsx': W2 / 'LP/Store Traning 2.xlsx',
    'lp-training-w3.xlsx': W3 / 'LP/Store Traning.xlsx',
    'finance-w1.docx': W1 / 'تقرير الادارة المالية.docx',
    'finance-w2.docx': W2 / 'Finance/تقرير الادارة المالية (1).docx',
    'finance-w3.docx': W3 / 'تقرير الادارة المالية.docx',
    'hr-w2.docx': W2 / 'HR/HR.docx',
    'hr-w3.docx': W3 / 'HR.docx',
    'maintenance-w2.xlsx': W2 / 'الصيانه/تقرير صيانه الفروع.xlsx',
    'maintenance-w3.xlsx': W3 / 'الصيانة.xlsx',
    'stock-w2.xlsx': W2 / 'Stock Control/متابعة_زيارات_الفروع.xlsx',
    'stock-w3.xlsx': W3 / 'Stock Control/متابعة_زيارات_الفروع (2).xlsx',
    'control-center.xlsx': ROOT.parents[1] / 'StoresAi/LeVoile_Control_Center_Database_FINAL.xlsx',
    'missing-reports-w1.docx': W1 / 'الإدارات التي لم ترسل تقارير في الإسبوع الأول.docx',
}

W = '{http://schemas.openxmlformats.org/wordprocessingml/2006/main}'
S = '{http://schemas.openxmlformats.org/spreadsheetml/2006/main}'
A = '{http://schemas.openxmlformats.org/drawingml/2006/main}'
R = '{http://schemas.openxmlformats.org/officeDocument/2006/relationships}'
MAX_ROWS = 500
CSS = """body{font:15px/1.7 Segoe UI,Tahoma,sans-serif;color:#231c20;background:#fff;margin:0;padding:20px}
h1{font-size:18px}h2{font-size:16px;margin-top:24px;color:#7a1656}.note{color:#726571;font-size:13px}
table{border-collapse:collapse;margin:10px 0;font-size:13px}td,th{border:1px solid #d9ced5;padding:5px 8px;vertical-align:top}
tr:first-child td{background:#f7e7f0;font-weight:600}img{max-width:100%;display:block;margin:10px 0;border:1px solid #e9e0e6}"""


def page(title, body):
    return f'<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>{html.escape(title)}</title><style>{CSS}</style><body><h1>{html.escape(title)}</h1>{body}</body></html>'


def rels(z, part):
    folder, name = posixpath.split(part)
    path = f'{folder}/_rels/{name}.rels'
    if path not in z.namelist():
        return {}
    return {r.get('Id'): posixpath.normpath(posixpath.join(folder, r.get('Target'))).lstrip('/') for r in E.fromstring(z.read(path)) if r.get('TargetMode') != 'External'}


def save_media(z, target, stem, assets):
    ext = posixpath.splitext(target)[1].lower()
    if ext not in ('.png', '.jpg', '.jpeg', '.gif') or target not in z.namelist():
        return ''
    name = f'{stem}-{posixpath.basename(target)}'
    (OUT / name).write_bytes(z.read(target))
    assets.add(name)
    return f'<img src="{html.escape(name)}" alt="">'


def docx(path, stem):
    out, assets = [], set()
    with zipfile.ZipFile(path) as z:
        rel = rels(z, 'word/document.xml')
        body = E.fromstring(z.read('word/document.xml')).find(W + 'body')

        def para(p):
            text = html.escape(''.join(t.text or '' for t in p.iter(W + 't')))
            imgs = ''.join(save_media(z, rel.get(b.get(R + 'embed'), ''), stem, assets) for b in p.iter(A + 'blip'))
            return (f'<p>{text}</p>' if text.strip() else '') + imgs

        for el in body:
            if el.tag == W + 'p':
                out.append(para(el))
            elif el.tag == W + 'tbl':
                rows = ''.join('<tr>' + ''.join('<td>' + ''.join(para(p) for p in c.iter(W + 'p')) + '</td>' for c in r.findall(W + 'tc')) + '</tr>' for r in el.findall(W + 'tr'))
                out.append(f'<table>{rows}</table>')
    return ''.join(out)


def pptx(path, stem):
    out, assets = [], set()
    with zipfile.ZipFile(path) as z:
        slides = sorted((n for n in z.namelist() if re.fullmatch(r'ppt/slides/slide\d+\.xml', n)), key=lambda n: int(re.search(r'\d+', posixpath.basename(n)).group()))
        for i, part in enumerate(slides, 1):
            rel = rels(z, part)
            root = E.fromstring(z.read(part))
            lines = [''.join(t.text or '' for t in p.iter(A + 't')) for p in root.iter(A + 'p')]
            out.append(f'<h2>شريحة {i}</h2>' + ''.join(f'<p>{html.escape(x)}</p>' for x in lines if x.strip()))
            out.extend(save_media(z, rel.get(b.get(R + 'embed'), ''), stem, assets) for b in root.iter(A + 'blip'))
    return ''.join(out)


def xlsx(path):
    out = []
    with zipfile.ZipFile(path) as z:
        strings = [''.join(t.text or '' for t in si.iter(S + 't')) for si in E.fromstring(z.read('xl/sharedStrings.xml'))] if 'xl/sharedStrings.xml' in z.namelist() else []
        date_styles = set()
        if 'xl/styles.xml' in z.namelist():
            styles = E.fromstring(z.read('xl/styles.xml'))
            custom = {n.get('numFmtId') for n in styles.iter(S + 'numFmt') if re.search(r'[yd]', re.sub(r'"[^"]*"|\[[^]]*]', '', n.get('formatCode', '')), re.I)}
            xfs = styles.find(S + 'cellXfs')
            for i, xf in enumerate(xfs if xfs is not None else []):
                num = xf.get('numFmtId')
                if num in custom or (num.isdigit() and (14 <= int(num) <= 17 or num == '22')):
                    date_styles.add(str(i))
        book = E.fromstring(z.read('xl/workbook.xml'))
        rel = rels(z, 'xl/workbook.xml')
        for sheet in book.find(S + 'sheets'):
            part = rel.get(sheet.get(R + 'id'))
            if part is None or sheet.get('state') in ('hidden', 'veryHidden'):
                continue
            rows, total = [], 0
            for row in E.fromstring(z.read(part)).iter(S + 'row'):
                cells = {}
                for c in row.findall(S + 'c'):
                    col = re.match(r'[A-Z]+', c.get('r', 'A')).group()
                    idx = 0
                    for ch in col:
                        idx = idx * 26 + ord(ch) - 64
                    v = c.find(S + 'v')
                    val = ''.join(t.text or '' for t in c.iter(S + 't')) if c.get('t') == 'inlineStr' else (v.text if v is not None else '')
                    if c.get('t') == 's' and val:
                        val = strings[int(val)]
                    elif val and c.get('s') in date_styles and c.get('t') != 'str':
                        try:
                            val = (date(1899, 12, 30) + timedelta(days=int(float(val)))).isoformat()
                        except ValueError:
                            pass
                    if val and val.strip():
                        cells[idx] = val
                if cells:
                    total += 1
                    if total <= MAX_ROWS:
                        rows.append(cells)
            if not rows:
                continue
            first, last = min(min(r) for r in rows), max(max(r) for r in rows)
            table = ''.join('<tr>' + ''.join(f'<td>{html.escape(r.get(i, ""))}</td>' for i in range(first, last + 1)) + '</tr>' for r in rows)
            more = f'<p class="note">معروض أول {MAX_ROWS} صف من {total}.</p>' if total > MAX_ROWS else ''
            out.append(f'<h2>{html.escape(sheet.get("name"))}</h2>{more}<table>{table}</table>')
    note = '<p class="note">عرض للقراءة بس، من غير تنسيق الملف الأصلي. التواريخ معروضة زي ما هي متخزنة في الملف.</p>'
    return note + ''.join(out)


def main():
    sys.stdout.reconfigure(encoding='utf-8')
    OUT.mkdir(parents=True, exist_ok=True)
    for name, src in FILES.items():
        shutil.copyfile(src, OUT / name)
        stem, ext = posixpath.splitext(name)
        body = {'.docx': lambda: docx(src, stem), '.pptx': lambda: pptx(src, stem), '.xlsx': lambda: xlsx(src)}.get(ext)
        if body:
            (OUT / f'{stem}.html').write_text(page(src.name, body()), encoding='utf-8')
        print('ok', name)


if __name__ == '__main__':
    main()
