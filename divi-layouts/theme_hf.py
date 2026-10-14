#!/usr/bin/env python3
"""Reemplaza el header (1114) y el footer (119) globales del Theme Builder por los shortcodes
de buckler-core: una sección sin padding > fila al 100 % > columna > módulo Texto con el shortcode.

Requisito: buckler-core >= 0.2.0 ACTIVO en el sitio (si no, se vería el shortcode en texto).

Uso:
  python3 divi-layouts/theme_hf.py            # dry_run (no cambia nada)
  python3 divi-layouts/theme_hf.py --apply    # guarda (crea snapshot antes; deshacer con undo_batch)
"""
import json, os, sys, time
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "tools"))
from mcp import call  # noqa: E402

BV = "5.6.2"  # builderVersion de las exportaciones reales del sitio
TARGETS = {1114: "[bk_header]", 119: "[bk_footer]"}


def block(name, attrs, inner=""):
    a = json.dumps(attrs, ensure_ascii=False, separators=(",", ":"))
    return f"<!-- wp:divi/{name} {a} -->\n{inner}<!-- /wp:divi/{name} -->\n" if inner or name != "text" else f"<!-- wp:divi/{name} {a} /-->\n"


def layout(shortcode):
    zero = {"top": "0px", "bottom": "0px", "left": "0px", "right": "0px", "syncVertical": "on", "syncHorizontal": "on"}
    text = block("text", {"content": {"innerContent": {"desktop": {"value": shortcode}}}, "builderVersion": BV})
    col = block("column", {"module": {"advanced": {"type": {"desktop": {"value": "4_4"}}},
                                      "decoration": {"sizing": {"desktop": {"value": {"flexType": "24_24"}}}}},
                           "builderVersion": BV}, text)
    row = block("row", {"module": {"advanced": {"columnStructure": {"desktop": {"value": "4_4"}},
                                                "flexColumnStructure": {"desktop": {"value": "equal-columns_1"}}},
                                   "decoration": {"layout": {"desktop": {"value": {"flexWrap": "nowrap", "columnGap": "0px", "rowGap": "0px"}}},
                                                  "sizing": {"desktop": {"value": {"width": "100%", "maxWidth": "100%"}}},
                                                  "spacing": {"desktop": {"value": {"padding": zero}}}}},
                        "builderVersion": BV}, col)
    sec = block("section", {"module": {"decoration": {"spacing": {"desktop": {"value": {"padding": zero}}}}},
                            "builderVersion": BV}, row)
    return f"<!-- wp:divi/placeholder -->\n{sec}<!-- /wp:divi/placeholder -->"


def main():
    apply = "--apply" in sys.argv
    for lid, sc in TARGETS.items():
        if apply:
            snap = call("list_theme_layouts", {"layout_id": lid})
            fn = f"snapshots/layout-{lid}-{time.strftime('%Y%m%d-%H%M%S')}.json"
            json.dump(snap, open(fn, "w"), ensure_ascii=False, indent=1)
            print("snapshot", fn)
        res = call("update_theme_layout", {"layout_id": lid, "content": layout(sc), "dry_run": not apply})
        print(lid, sc, json.dumps(res, ensure_ascii=False)[:600])


if __name__ == "__main__":
    main()
