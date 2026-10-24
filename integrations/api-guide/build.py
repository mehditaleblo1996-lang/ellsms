#!/usr/bin/env python3
"""Builds integrations/DatisRayan-API-Guide.pdf from cover.html, body.html and a generated contents page.

    python3 integrations/api-guide/build.py

Needs node with the playwright package (Chromium) and pypdf. Body pages are numbered from 1 in their
footer; the contents page lists the page each section actually starts on, read back from a marker pass.
"""
import re
import subprocess
import sys
import tempfile
from pathlib import Path

from pypdf import PdfReader, PdfWriter

HERE = Path(__file__).resolve().parent
OUT = HERE.parent / 'DatisRayan-API-Guide.pdf'

SECTIONS = [
    ('s1', 'شروع سریع', 'سه قدم تا اولین پیامک'),
    ('s2', 'احراز هویت و کلیدها', 'کلید API، دسترسی‌ها، محدودیت آی‌پی'),
    ('s3', 'انواع ارسال', 'کدام روش برای کدام کار'),
    ('s4', 'ارسال تکی و گروهی', 'مرجع کامل <span class="ltr">POST /messages</span>'),
    ('s5', 'ارسال انبوه و تدریجی', 'مرجع کامل <span class="ltr">POST /bulk-jobs</span>'),
    ('s6', 'پیش‌نمایش هزینه', 'هزینه را پیش از ارسال ببینید'),
    ('s7', 'جلوگیری از ارسال تکراری', '<span class="ltr">client_message_id</span> و <span class="ltr">Idempotency-Key</span>'),
    ('s8', 'سایر مسیرها', 'موجودی، مخاطبین، وبهوک‌ها'),
    ('s9', 'وبهوک‌ها', 'رویدادها و بررسی امضا'),
    ('s10', 'محدودیت‌ها', 'نرخ درخواست و سقف‌ها'),
    ('s11', 'خطاها', 'قالب خطا و همه‌ی کدها'),
    ('s12', 'نمونه کد', '<span class="ltr">PHP</span>، <span class="ltr">Python</span> و <span class="ltr">Node.js</span>'),
    ('s13', 'کتابخانه‌ها و منابع', '<span class="ltr">SDK</span>، <span class="ltr">OpenAPI</span> و چک‌لیست'),
]
FA_DIGITS = str.maketrans('0123456789', '۰۱۲۳۴۵۶۷۸۹')


def render(html: str, pdf: Path, flag: str = '') -> None:
    cmd = ['node', str(HERE / 'render.cjs'), str(HERE / html), str(pdf)] + ([flag] if flag else [])
    subprocess.run(cmd, check=True)


def section_pages(pdf: Path) -> dict:
    pages = {}
    for i, page in enumerate(PdfReader(pdf).pages, start=1):
        for sid in re.findall(r'QQMARK(s\d+)QQ', page.extract_text() or ''):
            pages.setdefault(sid, i)
    missing = [sid for sid, _, _ in SECTIONS if sid not in pages]
    if missing:
        sys.exit(f'section markers not found: {missing}')
    return pages


def contents_html(pages: dict) -> str:
    rows = ''.join(
        f'<tr><td class="no"><span>{str(i).translate(FA_DIGITS)}</span></td>'
        f'<td class="ti"><b>{title}</b> <small>{sub}</small></td><td class="pg">{pages[sid]}</td></tr>'
        for i, (sid, title, sub) in enumerate(SECTIONS, start=1)
    )
    return f'''<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">
<link rel="stylesheet" href="guide.css"><style>
@page {{ size: A4; margin: 22mm 17mm 18mm; }}
h1 {{ font-size: 22pt; font-weight: 800; color: var(--ink); margin: 0 0 4pt; }}
.lead {{ color: var(--muted); margin: 0 0 16pt; }}
table.toc {{ width: 100%; border-collapse: collapse; }}
table.toc td {{ border-bottom: 1px solid var(--line); padding: 9.5pt 0; vertical-align: middle; }}
td.no {{ width: 34pt; }}
td.no span {{ display: inline-flex; width: 22pt; height: 22pt; border-radius: 6pt; align-items: center; justify-content: center;
             background: var(--tint); color: var(--indigo); font-weight: 800; font-size: 9.5pt; }}
td.ti b {{ display: inline-block; font-size: 11.5pt; font-weight: 800; color: var(--ink); margin-left: 6pt; }}
td.ti small {{ font-size: 8.8pt; color: var(--muted); }}
td.pg {{ width: 30pt; text-align: left; font-weight: 800; color: var(--indigo); direction: ltr; }}
.help {{ margin-top: 20pt; text-align: center; font-size: 9pt; color: var(--muted); }}
</style></head><body>
<h1>فهرست مطالب</h1>
<p class="lead">اگر عجله دارید، بخش ۱ برای ارسال اولین پیامک کافی است. بخش‌های ۴ و ۵ مرجع کامل ارسال‌اند.</p>
<table class="toc">{rows}</table>
<p class="help">پرسش یا مشکلی دارید؟ با شناسه‌ی <span class="c">request_id</span> خطا به پشتیبانی داتیس رایان گستر ایرانیان پیام دهید.</p>
</body></html>'''


def main() -> None:
    with tempfile.TemporaryDirectory() as tmp:
        tmp = Path(tmp)
        render('body.html', tmp / 'markers.pdf', '--markers')
        pages = section_pages(tmp / 'markers.pdf')

        toc = HERE / '_contents.html'
        toc.write_text(contents_html(pages), encoding='utf-8')
        try:
            render('cover.html', tmp / 'cover.pdf')
            render('_contents.html', tmp / 'contents.pdf')
        finally:
            toc.unlink()
        render('body.html', tmp / 'body.pdf', '--footer')

        if len(PdfReader(tmp / 'body.pdf').pages) != len(PdfReader(tmp / 'markers.pdf').pages):
            sys.exit('marker pass and final pass paginated differently')

        writer = PdfWriter()
        for part in ('cover', 'contents', 'body'):
            writer.append(str(tmp / f'{part}.pdf'))
        front = len(PdfReader(tmp / 'cover.pdf').pages) + len(PdfReader(tmp / 'contents.pdf').pages)
        for sid, title, _ in SECTIONS:
            writer.add_outline_item(title, front + pages[sid] - 1)
        writer.add_metadata({
            '/Title': 'راهنمای API پیامک داتیس رایان گستر ایرانیان',
            '/Subject': 'Datis Rayan Gostar Iranian public SMS API v1',
            '/Author': 'Datis Rayan Gostar Iranian',
            '/Creator': 'Datis Rayan Gostar Iranian',
        })
        writer.page_mode = '/UseOutlines'
        with OUT.open('wb') as fh:
            writer.write(fh)
    print(f'wrote {OUT} ({front} front + body pages)')


if __name__ == '__main__':
    main()
