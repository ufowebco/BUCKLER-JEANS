#!/usr/bin/env python3
"""Genera docs/propuesta/index.html embebiendo imágenes (data URI) e iconos SVG.
Uso: python3 docs/propuesta/build.py <carpeta_imagenes_jpg>
"""
import base64, json, pathlib, re, sys

here = pathlib.Path(__file__).parent
imgdir = pathlib.Path(sys.argv[1])
src = (here / "src.html").read_text()

ICONS = {
    "heart": '<path d="M12 21s-7.5-4.6-9.5-9.2C1 8.3 3.3 5 6.6 5c2 0 3.4 1.1 4.2 2.4h2.4C14 6.1 15.4 5 17.4 5 20.7 5 23 8.3 21.5 11.8 19.5 16.4 12 21 12 21z"/>',
    "user": '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>',
    "bag": '<path d="M5 8h14l-1 13H6L5 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
    "truck": '<path d="M2 6h11v10H2zM13 10h4l3 3v3h-7z"/><circle cx="6" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
    "card": '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/>',
    "swap": '<path d="M4 8h13l-3-3M20 16H7l3 3"/>',
    "ruler": '<path d="M3 17 17 3l4 4L7 21z"/><path d="M7 13l2 2M10 10l2 2M13 7l2 2"/>',
}
def icon(m):
    return ('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" '
            'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICONS[m.group(1)] + '</svg>')

def uri(name):
    return "data:image/jpeg;base64," + base64.b64encode((imgdir / f"{name}.jpg").read_bytes()).decode()

used = set(re.findall(r"\{\{IMG:([\w-]+)\}\}", src)) | set(re.findall(r'"(\d{3,4}[RP]\d?)"', src))
cache = {n: uri(n) for n in sorted(used) if (imgdir / f"{n}.jpg").exists()}
out = re.sub(r"\{\{ICON:(\w+)\}\}", icon, src)
out = re.sub(r"\{\{IMG:([\w-]+)\}\}", lambda m: cache[m.group(1)], out)
out = out.replace("{{IMGMAP}}", json.dumps(cache)).replace("{{GRID}}", "")
(here / "index.html").write_text(out)
print("index.html", round(len(out) / 1024), "KB,", len(cache), "imágenes")
