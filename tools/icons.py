#!/usr/bin/env python3
"""Extrae los iconos de tools/icons.txt desde @iconify-json/* (tools/node_modules)
y genera buckler-core/assets/icons.json  ->  {"set:nombre": {"body", "width", "height"}}.

Uso: (cd tools && npm install) && python3 tools/icons.py
"""
import json, pathlib, sys

root = pathlib.Path(__file__).resolve().parent
wanted = [l.strip() for l in (root / "icons.txt").read_text().splitlines() if l.strip() and not l.startswith("#")]
sets, out, missing = {}, {}, []
for full in wanted:
    prefix, name = full.split(":", 1)
    if prefix not in sets:
        sets[prefix] = json.loads((root / "node_modules" / "@iconify-json" / prefix / "icons.json").read_text())
    data = sets[prefix]
    icon = data["icons"].get(name)
    if icon is None and name in data.get("aliases", {}):
        icon = data["icons"][data["aliases"][name]["parent"]]
    if icon is None:
        missing.append(full)
        continue
    out[full] = {
        "body": icon["body"],
        "width": icon.get("width", data.get("width", 24)),
        "height": icon.get("height", data.get("height", 24)),
    }
dest = root.parent / "buckler-core" / "assets" / "icons.json"
dest.write_text(json.dumps(out, separators=(",", ":"), ensure_ascii=False))
print(f"{len(out)} iconos -> {dest.relative_to(root.parent)} ({dest.stat().st_size} bytes)")
if missing:
    sys.exit("No encontrados: " + ", ".join(missing))
