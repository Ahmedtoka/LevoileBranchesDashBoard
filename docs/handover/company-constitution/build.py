"""Builds levoile-constitution.html: template.html with its {{SEC:heading}} slots filled from the research report.

Run with: python -I docs/handover/company-constitution/build.py   (from BranchesDashboard)
"""
import html
import re
import shutil
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
REPORT = ROOT.parent / 'reports' / 'دستور شركة Le Voile.md'
TAGS = {'[قانون]': ('law', 'قانون'), '[معيار]': ('std', 'معيار'), '[تصميم]': ('des', 'تصميم')}


def inline(text):
    out = html.escape(text, quote=False)
    out = out.replace('هذا البحث', 'البحث')
    out = re.sub(r'\*\*\[(قانون|معيار|تصميم)\]\*\*', lambda m: tag('[' + m.group(1) + ']'), out)
    out = re.sub(r'\[([^\]]+)\]\((https?://[^)\s]+)\)', r'<a href="\2" rel="noopener" target="_blank">\1</a>', out)
    return re.sub(r'\*\*([^*]+)\*\*', r'<strong>\1</strong>', out)


def tag(key):
    cls, label = TAGS[key]
    return f'<span class="tag {cls}">{label}</span>'


def cells(line):
    return [c.strip() for c in line.strip().strip('|').split('|')]


def render(lines):
    out, i = [], 0
    while i < len(lines):
        line = lines[i]
        if not line.strip():
            i += 1
        elif line.startswith('|'):
            rows = []
            while i < len(lines) and lines[i].startswith('|'):
                rows.append(lines[i])
                i += 1
            head = ''.join(f'<th>{inline(c)}</th>' for c in cells(rows[0]))
            body = ''.join('<tr>' + ''.join(f'<td>{inline(c)}</td>' for c in cells(r)) + '</tr>' for r in rows[2:])
            out.append(f'<div class="table"><table><tr>{head}</tr>{body}</table></div>')
        elif line.startswith('#'):
            out.append(f'<h3>{inline(line.lstrip("# ").strip())}</h3>')
            i += 1
        else:
            out.append(f'<p>{inline(line)}</p>')
            i += 1
    return '\n'.join(out)


def section(lines, key):
    starts = [i for i, l in enumerate(lines) if l.startswith('#') and key in l]
    assert len(starts) == 1, (key, starts)
    start = starts[0]
    level = len(lines[start]) - len(lines[start].lstrip('#'))
    end = next((i for i in range(start + 1, len(lines)) if lines[i].startswith('#') and len(lines[i]) - len(lines[i].lstrip('#')) <= level), len(lines))
    # The article's own heading is written by the template when the section is a whole chapter.
    return render(lines[start + 1:end] if level == 2 else lines[start:end])


def main():
    lines = REPORT.read_text(encoding='utf-8').splitlines()
    page = (HERE / 'template.html').read_text(encoding='utf-8')
    page = re.sub(r'\{\{SEC:([^}]+)\}\}', lambda m: section(lines, m.group(1)), page)
    page = page.replace('وطلب السؤال انتقالًا من الدستور إلى الإجراء مباشرة، لكن السياسة طبقة وسيطة لازمة لأنها تحمل المالك والحدود.', 'والسياسة طبقة وسيطة لازمة بين الدستور والإجراء لأنها تحمل المالك والحدود.')
    out = HERE.parent / 'levoile-constitution.html'
    out.write_text(page, encoding='utf-8')
    shutil.copyfile(out, ROOT / 'public' / 'handover' / out.name)
    print(out, len(page))


if __name__ == '__main__':
    main()
