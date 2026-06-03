from io import BytesIO
from zipfile import ZIP_DEFLATED, ZipFile
from xml.etree import ElementTree

from django.utils import timezone


MAIN_NS = "http://schemas.openxmlformats.org/spreadsheetml/2006/main"
OFFICE_REL_NS = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"
PACKAGE_REL_NS = "http://schemas.openxmlformats.org/package/2006/relationships"
CONTENT_TYPES_NS = "http://schemas.openxmlformats.org/package/2006/content-types"
XML_NS = "http://www.w3.org/XML/1998/namespace"

ElementTree.register_namespace("", MAIN_NS)
ElementTree.register_namespace("r", OFFICE_REL_NS)


CONVERSATION_EXPORT_HEADERS = [
    "Conversation ID",
    "Session Key",
    "Conversation Created At",
    "Conversation Updated At",
    "Message Number",
    "Message Role",
    "Message Created At",
    "Message Content",
]

CONVERSATION_EXPORT_WIDTHS = [38, 22, 22, 22, 15, 16, 22, 80]


def build_conversations_xlsx(conversations):
    if hasattr(conversations, "prefetch_related"):
        conversations = conversations.prefetch_related("messages")

    rows = [CONVERSATION_EXPORT_HEADERS]
    for conversation in conversations:
        messages = list(conversation.messages.all())
        if not messages:
            rows.append(
                [
                    str(conversation.public_id),
                    conversation.session_key or "",
                    _format_datetime(conversation.created_at),
                    _format_datetime(conversation.updated_at),
                    "",
                    "",
                    "",
                    "",
                ]
            )
            continue

        for index, message in enumerate(messages, start=1):
            rows.append(
                [
                    str(conversation.public_id),
                    conversation.session_key or "",
                    _format_datetime(conversation.created_at),
                    _format_datetime(conversation.updated_at),
                    index,
                    message.get_role_display(),
                    _format_datetime(message.created_at),
                    message.content,
                ]
            )

    return build_xlsx(
        sheet_name="Conversations",
        rows=rows,
        column_widths=CONVERSATION_EXPORT_WIDTHS,
    )


def build_xlsx(sheet_name, rows, column_widths=None):
    output = BytesIO()
    with ZipFile(output, "w", ZIP_DEFLATED) as archive:
        archive.writestr("[Content_Types].xml", _xml_bytes(_content_types_xml()))
        archive.writestr("_rels/.rels", _xml_bytes(_package_rels_xml()))
        archive.writestr("xl/workbook.xml", _xml_bytes(_workbook_xml(sheet_name)))
        archive.writestr("xl/_rels/workbook.xml.rels", _xml_bytes(_workbook_rels_xml()))
        archive.writestr("xl/styles.xml", _xml_bytes(_styles_xml()))
        archive.writestr(
            "xl/worksheets/sheet1.xml",
            _xml_bytes(_worksheet_xml(rows, column_widths or [])),
        )

    return output.getvalue()


def _tag(namespace, name):
    return f"{{{namespace}}}{name}"


def _xml_bytes(element):
    return ElementTree.tostring(element, encoding="utf-8", xml_declaration=True)


def _format_datetime(value):
    if not value:
        return ""

    if timezone.is_aware(value):
        value = timezone.localtime(value)

    return value.strftime("%Y-%m-%d %H:%M:%S")


def _content_types_xml():
    root = ElementTree.Element(_tag(CONTENT_TYPES_NS, "Types"))
    ElementTree.SubElement(
        root,
        _tag(CONTENT_TYPES_NS, "Default"),
        {
            "Extension": "rels",
            "ContentType": "application/vnd.openxmlformats-package.relationships+xml",
        },
    )
    ElementTree.SubElement(
        root,
        _tag(CONTENT_TYPES_NS, "Default"),
        {"Extension": "xml", "ContentType": "application/xml"},
    )
    ElementTree.SubElement(
        root,
        _tag(CONTENT_TYPES_NS, "Override"),
        {
            "PartName": "/xl/workbook.xml",
            "ContentType": (
                "application/vnd.openxmlformats-officedocument."
                "spreadsheetml.sheet.main+xml"
            ),
        },
    )
    ElementTree.SubElement(
        root,
        _tag(CONTENT_TYPES_NS, "Override"),
        {
            "PartName": "/xl/worksheets/sheet1.xml",
            "ContentType": (
                "application/vnd.openxmlformats-officedocument."
                "spreadsheetml.worksheet+xml"
            ),
        },
    )
    ElementTree.SubElement(
        root,
        _tag(CONTENT_TYPES_NS, "Override"),
        {
            "PartName": "/xl/styles.xml",
            "ContentType": (
                "application/vnd.openxmlformats-officedocument."
                "spreadsheetml.styles+xml"
            ),
        },
    )
    return root


def _package_rels_xml():
    root = ElementTree.Element(_tag(PACKAGE_REL_NS, "Relationships"))
    ElementTree.SubElement(
        root,
        _tag(PACKAGE_REL_NS, "Relationship"),
        {
            "Id": "rId1",
            "Type": (
                "http://schemas.openxmlformats.org/officeDocument/2006/"
                "relationships/officeDocument"
            ),
            "Target": "xl/workbook.xml",
        },
    )
    return root


def _workbook_xml(sheet_name):
    root = ElementTree.Element(_tag(MAIN_NS, "workbook"))
    sheets = ElementTree.SubElement(root, _tag(MAIN_NS, "sheets"))
    ElementTree.SubElement(
        sheets,
        _tag(MAIN_NS, "sheet"),
        {"name": sheet_name, "sheetId": "1", _tag(OFFICE_REL_NS, "id"): "rId1"},
    )
    return root


def _workbook_rels_xml():
    root = ElementTree.Element(_tag(PACKAGE_REL_NS, "Relationships"))
    ElementTree.SubElement(
        root,
        _tag(PACKAGE_REL_NS, "Relationship"),
        {
            "Id": "rId1",
            "Type": (
                "http://schemas.openxmlformats.org/officeDocument/2006/"
                "relationships/worksheet"
            ),
            "Target": "worksheets/sheet1.xml",
        },
    )
    ElementTree.SubElement(
        root,
        _tag(PACKAGE_REL_NS, "Relationship"),
        {
            "Id": "rId2",
            "Type": (
                "http://schemas.openxmlformats.org/officeDocument/2006/"
                "relationships/styles"
            ),
            "Target": "styles.xml",
        },
    )
    return root


def _styles_xml():
    root = ElementTree.Element(_tag(MAIN_NS, "styleSheet"))

    fonts = ElementTree.SubElement(root, _tag(MAIN_NS, "fonts"), {"count": "2"})
    _add_font(fonts)
    _add_font(fonts, bold=True)

    fills = ElementTree.SubElement(root, _tag(MAIN_NS, "fills"), {"count": "2"})
    ElementTree.SubElement(
        ElementTree.SubElement(fills, _tag(MAIN_NS, "fill")),
        _tag(MAIN_NS, "patternFill"),
        {"patternType": "none"},
    )
    ElementTree.SubElement(
        ElementTree.SubElement(fills, _tag(MAIN_NS, "fill")),
        _tag(MAIN_NS, "patternFill"),
        {"patternType": "gray125"},
    )

    borders = ElementTree.SubElement(root, _tag(MAIN_NS, "borders"), {"count": "1"})
    border = ElementTree.SubElement(borders, _tag(MAIN_NS, "border"))
    for name in ("left", "right", "top", "bottom", "diagonal"):
        ElementTree.SubElement(border, _tag(MAIN_NS, name))

    cell_style_xfs = ElementTree.SubElement(
        root,
        _tag(MAIN_NS, "cellStyleXfs"),
        {"count": "1"},
    )
    ElementTree.SubElement(
        cell_style_xfs,
        _tag(MAIN_NS, "xf"),
        {"numFmtId": "0", "fontId": "0", "fillId": "0", "borderId": "0"},
    )

    cell_xfs = ElementTree.SubElement(root, _tag(MAIN_NS, "cellXfs"), {"count": "2"})
    ElementTree.SubElement(
        cell_xfs,
        _tag(MAIN_NS, "xf"),
        {
            "numFmtId": "0",
            "fontId": "0",
            "fillId": "0",
            "borderId": "0",
            "xfId": "0",
        },
    )
    ElementTree.SubElement(
        cell_xfs,
        _tag(MAIN_NS, "xf"),
        {
            "numFmtId": "0",
            "fontId": "1",
            "fillId": "0",
            "borderId": "0",
            "xfId": "0",
            "applyFont": "1",
        },
    )

    cell_styles = ElementTree.SubElement(
        root,
        _tag(MAIN_NS, "cellStyles"),
        {"count": "1"},
    )
    ElementTree.SubElement(
        cell_styles,
        _tag(MAIN_NS, "cellStyle"),
        {"name": "Normal", "xfId": "0", "builtinId": "0"},
    )
    return root


def _add_font(fonts, bold=False):
    font = ElementTree.SubElement(fonts, _tag(MAIN_NS, "font"))
    if bold:
        ElementTree.SubElement(font, _tag(MAIN_NS, "b"))
    ElementTree.SubElement(font, _tag(MAIN_NS, "sz"), {"val": "11"})
    ElementTree.SubElement(font, _tag(MAIN_NS, "name"), {"val": "Calibri"})


def _worksheet_xml(rows, column_widths):
    root = ElementTree.Element(_tag(MAIN_NS, "worksheet"))
    if rows:
        last_cell = f"{_column_name(len(rows[0]))}{len(rows)}"
        ElementTree.SubElement(root, _tag(MAIN_NS, "dimension"), {"ref": f"A1:{last_cell}"})

    sheet_views = ElementTree.SubElement(root, _tag(MAIN_NS, "sheetViews"))
    sheet_view = ElementTree.SubElement(
        sheet_views,
        _tag(MAIN_NS, "sheetView"),
        {"workbookViewId": "0"},
    )
    ElementTree.SubElement(
        sheet_view,
        _tag(MAIN_NS, "pane"),
        {
            "ySplit": "1",
            "topLeftCell": "A2",
            "activePane": "bottomLeft",
            "state": "frozen",
        },
    )

    if column_widths:
        cols = ElementTree.SubElement(root, _tag(MAIN_NS, "cols"))
        for index, width in enumerate(column_widths, start=1):
            ElementTree.SubElement(
                cols,
                _tag(MAIN_NS, "col"),
                {
                    "min": str(index),
                    "max": str(index),
                    "width": str(width),
                    "customWidth": "1",
                },
            )

    sheet_data = ElementTree.SubElement(root, _tag(MAIN_NS, "sheetData"))
    for row_number, values in enumerate(rows, start=1):
        row = ElementTree.SubElement(
            sheet_data,
            _tag(MAIN_NS, "row"),
            {"r": str(row_number)},
        )
        for column_number, value in enumerate(values, start=1):
            attributes = {
                "r": f"{_column_name(column_number)}{row_number}",
                "t": "inlineStr",
            }
            if row_number == 1:
                attributes["s"] = "1"

            cell = ElementTree.SubElement(row, _tag(MAIN_NS, "c"), attributes)
            inline_string = ElementTree.SubElement(cell, _tag(MAIN_NS, "is"))
            text = ElementTree.SubElement(
                inline_string,
                _tag(MAIN_NS, "t"),
                {_tag(XML_NS, "space"): "preserve"},
            )
            text.text = _cell_text(value)

    return root


def _cell_text(value):
    text = "" if value is None else str(value)
    return "".join(character for character in text if _is_valid_xml_character(character))


def _is_valid_xml_character(character):
    codepoint = ord(character)
    return (
        codepoint in {0x09, 0x0A, 0x0D}
        or 0x20 <= codepoint <= 0xD7FF
        or 0xE000 <= codepoint <= 0xFFFD
        or 0x10000 <= codepoint <= 0x10FFFF
    )


def _column_name(index):
    name = ""
    while index:
        index, remainder = divmod(index - 1, 26)
        name = chr(65 + remainder) + name
    return name
