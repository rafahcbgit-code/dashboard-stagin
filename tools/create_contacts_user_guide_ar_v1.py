from pathlib import Path
import re

from docx import Document
from docx.enum.section import WD_SECTION
from docx.enum.style import WD_STYLE_TYPE
from docx.enum.table import WD_TABLE_ALIGNMENT, WD_CELL_VERTICAL_ALIGNMENT
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Inches, Pt, RGBColor


ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "docs" / "CONTACTS_USER_GUIDE_AR_v1.md"
OUTPUT = ROOT / "docs" / "CONTACTS_USER_GUIDE_AR_v1.docx"


def set_rtl(paragraph):
    paragraph.alignment = WD_ALIGN_PARAGRAPH.RIGHT
    ppr = paragraph._p.get_or_add_pPr()
    bidi = ppr.find(qn("w:bidi"))
    if bidi is None:
        bidi = OxmlElement("w:bidi")
        ppr.append(bidi)


def set_cell_shading(cell, fill):
    tcpr = cell._tc.get_or_add_tcPr()
    shd = tcpr.find(qn("w:shd"))
    if shd is None:
        shd = OxmlElement("w:shd")
        tcpr.append(shd)
    shd.set(qn("w:fill"), fill)


def set_cell_margins(cell, top=90, start=100, bottom=90, end=100):
    tc = cell._tc
    tcpr = tc.get_or_add_tcPr()
    tc_mar = tcpr.first_child_found_in("w:tcMar")
    if tc_mar is None:
        tc_mar = OxmlElement("w:tcMar")
        tcpr.append(tc_mar)
    for margin, value in (("top", top), ("start", start), ("bottom", bottom), ("end", end)):
        node = tc_mar.find(qn(f"w:{margin}"))
        if node is None:
            node = OxmlElement(f"w:{margin}")
            tc_mar.append(node)
        node.set(qn("w:w"), str(value))
        node.set(qn("w:type"), "dxa")


def set_table_rtl(table):
    table.alignment = WD_TABLE_ALIGNMENT.CENTER
    tblpr = table._tbl.tblPr
    bidi = tblpr.find(qn("w:bidiVisual"))
    if bidi is None:
        bidi = OxmlElement("w:bidiVisual")
        tblpr.append(bidi)


def add_page_number(paragraph):
    run = paragraph.add_run()
    fld_char1 = OxmlElement("w:fldChar")
    fld_char1.set(qn("w:fldCharType"), "begin")
    instr_text = OxmlElement("w:instrText")
    instr_text.set(qn("xml:space"), "preserve")
    instr_text.text = " PAGE "
    fld_char2 = OxmlElement("w:fldChar")
    fld_char2.set(qn("w:fldCharType"), "end")
    run._r.append(fld_char1)
    run._r.append(instr_text)
    run._r.append(fld_char2)


def configure_styles(document):
    styles = document.styles
    normal = styles["Normal"]
    normal.font.name = "Arial"
    normal._element.rPr.rFonts.set(qn("w:eastAsia"), "Arial")
    normal.font.size = Pt(10.5)
    normal.font.color.rgb = RGBColor(31, 41, 55)
    normal.paragraph_format.space_after = Pt(6)
    normal.paragraph_format.line_spacing = 1.2

    for style_name, size, color, before, after in [
        ("Title", 25, "0F172A", 0, 16),
        ("Heading 1", 17, "0F3B67", 16, 8),
        ("Heading 2", 13, "0F766E", 12, 6),
        ("Heading 3", 11, "334155", 9, 4),
    ]:
        style = styles[style_name]
        style.font.name = "Arial"
        style._element.rPr.rFonts.set(qn("w:eastAsia"), "Arial")
        style.font.size = Pt(size)
        style.font.bold = True
        style.font.color.rgb = RGBColor.from_string(color)
        style.paragraph_format.space_before = Pt(before)
        style.paragraph_format.space_after = Pt(after)
        style.paragraph_format.keep_with_next = True

    for style_name in ("List Bullet", "List Number"):
        style = styles[style_name]
        style.font.name = "Arial"
        style._element.rPr.rFonts.set(qn("w:eastAsia"), "Arial")
        style.font.size = Pt(10.5)
        style.paragraph_format.space_after = Pt(3)

    if "Callout" not in styles:
        callout = styles.add_style("Callout", WD_STYLE_TYPE.PARAGRAPH)
        callout.base_style = styles["Normal"]
        callout.font.name = "Arial"
        callout._element.rPr.rFonts.set(qn("w:eastAsia"), "Arial")
        callout.font.size = Pt(10.5)
        callout.paragraph_format.space_before = Pt(5)
        callout.paragraph_format.space_after = Pt(8)
        callout.paragraph_format.left_indent = Inches(0.12)
        callout.paragraph_format.right_indent = Inches(0.12)


def add_inline_runs(paragraph, text):
    pattern = re.compile(r"(\*\*.*?\*\*|`.*?`)")
    parts = pattern.split(text)
    for part in parts:
        if not part:
            continue
        bold = part.startswith("**") and part.endswith("**")
        code = part.startswith("`") and part.endswith("`")
        value = part[2:-2] if bold else part[1:-1] if code else part
        run = paragraph.add_run(value)
        run.font.name = "Arial"
        run._element.rPr.rFonts.set(qn("w:eastAsia"), "Arial")
        run.font.size = Pt(10.5)
        run.bold = bold
        if code:
            run.font.name = "Consolas"
            run._element.rPr.rFonts.set(qn("w:eastAsia"), "Consolas")
            run.font.color.rgb = RGBColor(71, 85, 105)


def add_text_paragraph(document, text, style="Normal"):
    paragraph = document.add_paragraph(style=style)
    set_rtl(paragraph)
    add_inline_runs(paragraph, text)
    return paragraph


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

    column_count = max(len(row) for row in rows)
    table = document.add_table(rows=len(rows), cols=column_count)
    table.style = "Table Grid"
    set_table_rtl(table)
    table.autofit = True
    for row_index, row in enumerate(rows):
        for col_index in range(column_count):
            cell = table.cell(row_index, col_index)
            cell.vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.CENTER
            set_cell_margins(cell)
            if row_index == 0:
                set_cell_shading(cell, "DCEAF5")
            elif row_index % 2 == 0:
                set_cell_shading(cell, "F8FAFC")
            paragraph = cell.paragraphs[0]
            set_rtl(paragraph)
            paragraph.paragraph_format.space_after = Pt(0)
            value = row[col_index] if col_index < len(row) else ""
            add_inline_runs(paragraph, value)
            if row_index == 0:
                for run in paragraph.runs:
                    run.bold = True
                    run.font.color.rgb = RGBColor(15, 59, 103)
    document.add_paragraph().paragraph_format.space_after = Pt(1)


def build_document():
    document = Document()
    configure_styles(document)
    section = document.sections[0]
    section.top_margin = Inches(0.65)
    section.bottom_margin = Inches(0.65)
    section.left_margin = Inches(0.7)
    section.right_margin = Inches(0.7)
    section.header_distance = Inches(0.3)
    section.footer_distance = Inches(0.3)

    header = section.header.paragraphs[0]
    set_rtl(header)
    header.text = "ChatBerry | دليل المستخدم – جهات الاتصال"
    header.runs[0].font.name = "Arial"
    header.runs[0].font.size = Pt(8)
    header.runs[0].font.color.rgb = RGBColor(100, 116, 139)

    footer = section.footer.paragraphs[0]
    footer.alignment = WD_ALIGN_PARAGRAPH.CENTER
    run = footer.add_run("النسخة الأولى | صفحة ")
    run.font.name = "Arial"
    run.font.size = Pt(8)
    run.font.color.rgb = RGBColor(100, 116, 139)
    add_page_number(footer)

    lines = SOURCE.read_text(encoding="utf-8").splitlines()
    index = 0
    title_added = False
    while index < len(lines):
        line = lines[index]
        if not line.strip():
            index += 1
            continue

        if line.startswith("|"):
            table_lines = []
            while index < len(lines) and lines[index].strip().startswith("|"):
                table_lines.append(lines[index])
                index += 1
            add_table(document, table_lines)
            continue

        heading = re.match(r"^(#{1,3})\s+(.*)$", line)
        if heading:
            level = len(heading.group(1))
            text = heading.group(2).strip()
            style = "Title" if level == 1 else f"Heading {level}"
            paragraph = add_text_paragraph(document, text, style=style)
            if level == 1 and not title_added:
                title_added = True
                paragraph.paragraph_format.space_before = Pt(0)
            index += 1
            continue

        bullet = re.match(r"^\s*-\s+(.*)$", line)
        if bullet:
            add_text_paragraph(document, bullet.group(1), style="List Bullet")
            index += 1
            continue

        numbered = re.match(r"^\s*\d+\.\s+(.*)$", line)
        if numbered:
            add_text_paragraph(document, numbered.group(1), style="List Number")
            index += 1
            continue

        if line.startswith("---"):
            index += 1
            continue

        style = "Callout" if line.startswith("**حالة استخدام") else "Normal"
        paragraph = add_text_paragraph(document, line, style=style)
        if style == "Callout":
            ppr = paragraph._p.get_or_add_pPr()
            shd = OxmlElement("w:shd")
            shd.set(qn("w:fill"), "EFF6FF")
            ppr.append(shd)
            paragraph.paragraph_format.left_indent = Inches(0.12)
            paragraph.paragraph_format.right_indent = Inches(0.12)
        index += 1

    document.core_properties.title = "دليل المستخدم – قسم جهات الاتصال"
    document.core_properties.subject = "شرح ميزات وحالات استخدام قسم جهات الاتصال في ChatBerry"
    document.core_properties.author = "ChatBerry"
    document.core_properties.keywords = "ChatBerry, Contacts, User Guide, Arabic"
    document.save(OUTPUT)


if __name__ == "__main__":
    build_document()
    print(OUTPUT)
