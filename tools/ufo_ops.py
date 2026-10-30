#!/usr/bin/env python3
"""Cliente de UfoWeb Ops (POST /wp-json/ufo-ops/v1/run) — genérico para cualquier sitio.

La clave la inyecta el proxy del entorno (cabecera X-AIED-Authorization, la misma del MCP); aquí no se escribe nunca.
Requisitos en el sitio: plugin UfoWeb Ops activado (Herramientas > UfoWeb Ops) y mu-plugin auth-bridge.php 2.1+.

Uso (dominio por --base= o variable UFO_OPS_BASE):
  python3 tools/ufo_ops.py --base=https://dominio.com estado
  ... log [lineas] [filtro]            ... purgar
  ... instalar <plugin.zip> [--activar] ... leer <id>
  ... listar <tipo> [buscar]           ... guardar '<json>'   ({"id"?, "tipo", "titulo", "estado", "meta": {...}})
  ... papelera <id>                    ... opciones <patron>
  ... limpiar autoload_off|borrar <nombre>...
  ... respaldo | respaldos | registro | ping
Pruebas locales: UFO_OPS_HEADER="Nombre: valor" (solo se envía si la base no es https).
"""
import base64, hashlib, json, os, sys, urllib.error, urllib.request

BASE = os.environ.get("UFO_OPS_BASE", "").rstrip("/")


def http(path, body=None):
    headers = {"Content-Type": "application/json", "Accept": "application/json", "User-Agent": "ufoweb-ops-client/1.0"}
    extra = os.environ.get("UFO_OPS_HEADER")
    if extra and not BASE.startswith("https://"):
        k, v = extra.split(":", 1)
        headers[k.strip()] = v.strip()
    req = urllib.request.Request(BASE + path, data=json.dumps(body).encode() if body is not None else None,
                                 method="POST" if body is not None else "GET", headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=600) as r:
            return r.status, json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        txt = e.read().decode()
        try:
            return e.code, json.loads(txt)
        except ValueError:
            return e.code, {"error": txt[:500]}
    except urllib.error.URLError as e:
        sys.exit(f"Sin conexión con {BASE}: {e.reason}")


def ops(accion, **kw):
    # "via" mantiene compatibilidad con auth-bridge 2.0 (solo pasa la clave si la URL contiene ai-editor-divi5/).
    return http("/wp-json/ufo-ops/v1/run?via=ai-editor-divi5/", {"accion": accion, **kw})


def main(argv):
    global BASE
    flags = [a for a in argv if a.startswith("--")]
    args = [a for a in argv if not a.startswith("--")]
    for f in flags:
        if f.startswith("--base="):
            BASE = f.split("=", 1)[1].rstrip("/")
    if not args or not BASE:
        sys.exit(__doc__)
    cmd, rest = args[0], args[1:]
    if cmd == "ping":
        code, res = http("/wp-json/ufo-ops/v1/ping")
        print(json.dumps(res, ensure_ascii=False, indent=1))
        return
    if cmd == "log":
        code, res = ops("log", lineas=int(rest[0]) if rest else 100, filtro=rest[1] if len(rest) > 1 else None)
        if code == 200:
            d = res["datos"]
            print(f"{d['archivo']} · {d['kb']} KB · modificado {d['modificado']}")
            print("\n".join(d["lineas"]))
            return
    elif cmd == "instalar":
        if not rest:
            sys.exit("Indica el zip del plugin.")
        data = open(rest[0], "rb").read()
        print(f"Subiendo {os.path.basename(rest[0])} ({len(data) // 1024} KB)…")
        code, res = ops("instalar", zip=base64.b64encode(data).decode(), sha256=hashlib.sha256(data).hexdigest(),
                        activar="--activar" in flags)
    elif cmd == "leer":
        code, res = ops("post_leer", id=int(rest[0]))
    elif cmd == "listar":
        code, res = ops("post_listar", tipo=rest[0] if rest else "post", buscar=rest[1] if len(rest) > 1 else "")
    elif cmd == "guardar":
        code, res = ops("post_guardar", **json.loads(rest[0]))
    elif cmd == "papelera":
        code, res = ops("post_papelera", id=int(rest[0]))
    elif cmd == "opciones":
        code, res = ops("opciones", patron=rest[0])
    elif cmd == "limpiar":
        code, res = ops("opciones_limpiar", modo=rest[0], nombres=rest[1:])
    elif cmd in ("estado", "purgar", "respaldo", "respaldos", "registro"):
        code, res = ops(cmd)
    else:
        sys.exit(__doc__)
    print(json.dumps(res.get("datos", res) if code == 200 else res, ensure_ascii=False, indent=1))
    if code != 200:
        sys.exit(f"HTTP {code}")


if __name__ == "__main__":
    main(sys.argv[1:])
