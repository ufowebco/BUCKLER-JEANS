#!/usr/bin/env python3
"""Cliente mínimo del MCP de Divi 5 AI Editor Pro (buckler.com.co).

La autenticación la inyecta el proxy del entorno (cabecera X-AIED-Authorization).
Nunca escribas la clave aquí.

Uso:
  python3 tools/mcp.py ping
  python3 tools/mcp.py tools [--names]
  python3 tools/mcp.py call <tool> '<json args>'
  python3 tools/mcp.py pull <page_id> [--out snapshots/]
  python3 tools/mcp.py rest [/ruta]          # GET a la API REST del plugin
"""
import json, os, sys, time, urllib.request, urllib.error

BASE = "https://buckler.com.co/wp-json/ai-editor-divi5/v1"
MCP = BASE + "/mcp"
CA = os.environ.get("SSL_CERT_FILE") or "/root/.ccr/ca-bundle.crt"
_id = 0


def _ctx():
    import ssl
    return ssl.create_default_context(cafile=CA) if os.path.exists(CA) else None


def http(url, data=None, method=None):
    body = json.dumps(data).encode() if data is not None else None
    req = urllib.request.Request(url, data=body, method=method or ("POST" if body else "GET"),
                                 headers={"Content-Type": "application/json", "Accept": "application/json",
                                          "User-Agent": "buckler-claude/1.0"})
    try:
        with urllib.request.urlopen(req, context=_ctx(), timeout=180) as r:
            return r.status, json.loads(r.read().decode() or "null")
    except urllib.error.HTTPError as e:
        txt = e.read().decode(errors="replace")
        try:
            return e.code, json.loads(txt)
        except Exception:
            return e.code, txt


def rpc(method, params=None):
    global _id
    _id += 1
    code, res = http(MCP, {"jsonrpc": "2.0", "id": _id, "method": method, "params": params or {}})
    if code in (401, 403):
        sys.exit(f"HTTP {code}: {'la clave no llega' if code == 401 else 'clave no vigente'} -> {res}")
    if isinstance(res, dict) and "error" in res:
        raise RuntimeError(json.dumps(res["error"], ensure_ascii=False))
    return res["result"] if isinstance(res, dict) else res


def call(tool, args=None):
    """Llama una herramienta y devuelve el JSON decodificado (o el texto)."""
    r = rpc("tools/call", {"name": tool, "arguments": args or {}})
    out = []
    for c in r.get("content", []):
        if c.get("type") == "text":
            try:
                out.append(json.loads(c["text"]))
            except Exception:
                out.append(c["text"])
    if r.get("isError"):
        raise RuntimeError(json.dumps(out, ensure_ascii=False)[:4000])
    return out[0] if len(out) == 1 else out


def main(a):
    if not a or a[0] == "ping":
        print(json.dumps(rpc("initialize", {"protocolVersion": "2024-11-05", "capabilities": {},
                                            "clientInfo": {"name": "buckler-claude", "version": "1"}}), indent=2))
    elif a[0] == "tools":
        tools = rpc("tools/list")["tools"]
        if "--names" in a:
            print("\n".join(t["name"] for t in tools)); print(len(tools), "tools")
        else:
            print(json.dumps(tools, indent=2, ensure_ascii=False))
    elif a[0] == "call":
        args = json.loads(a[2]) if len(a) > 2 else {}
        print(json.dumps(call(a[1], args), indent=2, ensure_ascii=False))
    elif a[0] == "pull":
        pid = a[1]
        out = a[a.index("--out") + 1] if "--out" in a else "snapshots"
        os.makedirs(out, exist_ok=True)
        data = call("get_page_layout", {"page_id": int(pid)})
        fn = os.path.join(out, f"page-{pid}-{time.strftime('%Y%m%d-%H%M%S')}.json")
        with open(fn, "w") as f:
            json.dump(data, f, indent=2, ensure_ascii=False)
        print("guardado", fn)
    elif a[0] == "rest":
        code, res = http(BASE + (a[1] if len(a) > 1 else ""))
        print(code); print(json.dumps(res, indent=2, ensure_ascii=False))
    else:
        print(__doc__)


if __name__ == "__main__":
    main(sys.argv[1:])
