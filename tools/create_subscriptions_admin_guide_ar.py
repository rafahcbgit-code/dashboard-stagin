from pathlib import Path
import re

from docx import Document
from docx.enum.table import WD_CELL_VERTICAL_ALIGNMENT, WD_TABLE_ALIGNMENT
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Inches, Pt, RGBColor


ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "docs" / "Chatberry_Subscriptions_Admin_User_Guide_AR.md"
OUTPUT = ROOT / "docs" / "Chatberry_Subscriptions_Admin_User_Guide_AR.docx"


def rtl_paragraph(paragraph):
    paragraph.alignment = WD_ALIGN_PARAGRAPH.RIGHT
    ppr = paragraph._p.get_or_add_pPr()
    bidi = ppr.find(qn("w:bidi"))
    if bidi is None:
        bidi = OxmlElement("w:bidi")
        ppr.append(bidi)


def set_run_font(run, size=10.5, bold=False, color=None):
    run.font.name = "Arial"
    run._element.get_or_add_rPr().rFonts.set(qn("w:ascii"), "Arial")
    run._element.get_or_add_rPr().rFonts.set(qn("w:hAnsi"), "Arial")
    run._element.get_or_add_rPr().rFonts.set(qn("w:cs"), "Arial")
    run.font.size = Pt(size)
    run.bold = bold
    if color:
        run.font.color.rgb = RGBColor.from_string(color)


def add_inline(paragraph, text, size=10.5, color=None):
    for part in re.split(r"(\*\*.*?\*\*)", text):
        if not part:
            continue
        bold = part.startswith("**") and part.endswith("**")
        value = part[2:-2] if bold else part
        run = paragraph.add_run(value)
        set_run_font(run, size=size, bold=bold, color=color)
        rpr = run._element.get_or_add_rPr()
        rtl = rpr.find(qn("w:rtl"))
        if rtl is None:
            rtl = OxmlElement("w:rtl")
            rpr.append(rtl)


def shade(cell, fill):
    tcpr = cell._tc.get_or_add_tcPr()
    shd = tcpr.find(qn("w:shd"))
    if shd is None:
        shd = OxmlElement("w:shd")
        tcpr.append(shd)
    shd.set(qn("w:fill"), fill)


def cell_margins(cell):
    tcpr = cell._tc.get_or_add_tcPr()
    margins = OxmlElement("w:tcMar")
    for edge, value in (("top", 110), ("start", 130), ("bottom", 110), ("end", 130)):
        node = OxmlElement(f"w:{edge}")
        node.set(qn("w:w"), str(value))
        node.set(qn("w:type"), "dxa")
        margins.append(node)
    tcpr.append(margins)


def add_table(document, lines):
    rows = []
    for line in lines:
        if not line.strip().startswith("|"):
            continue
        values = [value.strip() for value in line.strip().strip("|").split("|")]
        if all(re.fullmatch(r"[-: ]+", value or "-") for value in values):
            continue
        rows.append(values)
    if not rows:
        return
    count = max(map(len, rows))
    table = document.add_table(rows=len(rows), cols=count)
    table.style = "Table Grid"
    table.alignment = WD_TABLE_ALIGNMENT.CENTER
    table.autofit = True
    bidi = OxmlElement("w:bidiVisual")
    table._tbl.tblPr.append(bidi)
    for ri, row in enumerate(rows):
        if ri == 0:
            trpr = table.rows[ri]._tr.get_or_add_trPr()
            repeat = OxmlElement("w:tblHeader")
            repeat.set(qn("w:val"), "true")
            trpr.append(repeat)
        for ci in range(count):
            cell = table.cell(ri, ci)
            cell.vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.CENTER
            cell_margins(cell)
            if ri == 0:
                shade(cell, "17365D")
            elif ri % 2 == 0:
                shade(cell, "F3F6FA")
            p = cell.paragraphs[0]
            rtl_paragraph(p)
            p.paragraph_format.space_after = Pt(0)
            add_inline(p, row[ci] if ci < len(row) else "", size=9.5,
                       color="FFFFFF" if ri == 0 else "1F2937")
            if ri == 0:
                for run in p.runs:
                    run.bold = True
    document.add_paragraph().paragraph_format.space_after = Pt(1)


def build():
    doc = Document()
    section = doc.sections[0]
    section.top_margin = Inches(0.7)
    section.bottom_margin = Inches(0.65)
    section.left_margin = Inches(0.72)
    section.right_margin = Inches(0.72)
    section.header_distance = Inches(0.3)
    section.footer_distance = Inches(0.3)

    normal = doc.styles["Normal"]
    normal.font.name = "Arial"
    normal._element.rPr.rFonts.set(qn("w:ascii"), "Arial")
    normal._element.rPr.rFonts.set(qn("w:hAnsi"), "Arial")
    normal._element.rPr.rFonts.set(qn("w:cs"), "Arial")
    normal.font.size = Pt(10.5)
    normal.font.color.rgb = RGBColor(31, 41, 55)
    normal.paragraph_format.space_after = Pt(5)
    normal.paragraph_format.line_spacing = 1.14
    for style_name, size in (("Title", 25), ("Heading 1", 16), ("Heading 2", 12.5)):
        style = doc.styles[style_name]
        style.font.name = "Arial"
        style._element.rPr.rFonts.set(qn("w:ascii"), "Arial")
        style._element.rPr.rFonts.set(qn("w:hAnsi"), "Arial")
        style._element.rPr.rFonts.set(qn("w:cs"), "Arial")
        style.font.size = Pt(size)
        style.font.bold = True
        style.font.color.rgb = RGBColor(0, 0, 0)
        style.paragraph_format.keep_with_next = True
        style.paragraph_format.space_before = Pt(12 if style_name != "Title" else 0)
        style.paragraph_format.space_after = Pt(7)

    header = section.header.paragraphs[0]
    rtl_paragraph(header)
    add_inline(header, "Chatberry | دليل المشرف للباقات والاشتراكات", 8, "64748B")
    footer = section.footer.paragraphs[0]
    footer.alignment = WD_ALIGN_PARAGRAPH.CENTER
    add_inline(footer, "دليل فريق العمل  |  ", 8, "64748B")
    field = OxmlElement("w:fldSimple")
    field.set(qn("w:instr"), "PAGE")
    footer._p.append(field)

    lines = SOURCE.read_text(encoding="utf-8").splitlines()
    i = 0
    while i < len(lines):
        line = lines[i]
        if not line.strip():
            i += 1
            continue
        if line.startswith("|"):
            group = []
            while i < len(lines) and lines[i].strip().startswith("|"):
                group.append(lines[i])
                i += 1
            add_table(doc, group)
            continue
        heading = re.match(r"^(#{1,3})\s+(.*)$", line)
        if heading:
            level = len(heading.group(1))
            p = doc.add_paragraph(style="Title" if level == 1 else f"Heading {min(level, 2)}")
            rtl_paragraph(p)
            add_inline(p, heading.group(2).strip(), size=25 if level == 1 else (16 if level == 2 else 12.5), color="000000")
            i += 1
            continue
        if line.startswith("---"):
            i += 1
            continue
        bullet = re.match(r"^\s*-\s+(.*)$", line)
        numbered = re.match(r"^\s*\d+\.\s+(.*)$", line)
        style = "List Bullet" if bullet else "List Number" if numbered else "Normal"
        value = bullet.group(1) if bullet else numbered.group(1) if numbered else line
        p = doc.add_paragraph(style=style)
        rtl_paragraph(p)
        add_inline(p, value)
        i += 1

    doc.core_properties.title = "دليل المشرف للباقات والاشتراكات"
    doc.core_properties.subject = "دليل عربي لإدارة باقات واشتراكات Chatberry"
    doc.core_properties.author = "Chatberry"
    doc.save(OUTPUT)
    print(OUTPUT)


if __name__ == "__main__":
    build()
