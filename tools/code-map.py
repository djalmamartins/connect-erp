#!/usr/bin/env python3
"""Connect ERP — mapa do código (ADR 0016).

Lê o repositório sem subir o PHP nem o banco e gera:
  docs/map/README.md       mapa navegável (Mermaid + índices) para abrir no GitHub
  docs/map/code-map.json   o mesmo grafo em JSON, para scripts e para o Claude

O grafo liga: ambiente (web/cms/erp/send/api) -> rota -> controller -> service/model -> tabela,
e também testes -> classes, migrations -> tabelas e ADRs.

Uso:
  python3 tools/code-map.py            # regenera docs/map/
  python3 tools/code-map.py --check    # sai com 1 se docs/map/ estiver desatualizado
  python3 tools/code-map.py --find X   # procura X (rota, classe, tabela, ADR) e mostra as ligações

Sem dependências além do Python 3 da máquina. Em conflito de merge em docs/map/, basta rodar
de novo: os arquivos são gerados.
"""
from __future__ import annotations

import json
import re
import sys
from collections import defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "docs" / "map"
VERBS = "get|post|put|patch|delete"

ROUTE_FILES = {
    "index": ROOT / "index.php",
    "web": ROOT / "container/web/default/v1/routes.php",
    "support": ROOT / "container/web/support/v1/routes.php",
    "studio": ROOT / "container/cms/studio/v1/routes.php",
    "helpdesk": ROOT / "container/cms/helpdesk/v1/routes.php",
    "erp": ROOT / "container/erp/connect/v1/routes.php",
}

RE_NS = re.compile(r'\$route->namespace\(\s*(null|"([^"]*)"|\'([^\']*)\')\s*\)')
RE_GROUP = re.compile(r'\$route->group\(\s*(null|"([^"]*)"|\'([^\']*)\'|\$\w+)\s*\)')
RE_ROUTE = re.compile(r'\$route->(' + VERBS + r')\(\s*(["\'])([^"\']*)\2\s*,\s*(?:(["\'])([A-Za-z0-9_]+):([A-Za-z0-9_]+)\4|static fn|function)')
RE_DYN = re.compile(r'\$route->(' + VERBS + r')\(\s*\$')
RE_CLASS = re.compile(r'^\s*(?:final\s+|abstract\s+|readonly\s+)*(class|interface|trait|enum)\s+([A-Za-z0-9_]+)', re.M)
RE_NAMESPACE = re.compile(r'^\s*namespace\s+([A-Za-z0-9_\\]+)\s*;', re.M)
RE_USE = re.compile(r'^\s*use\s+(Source\\[A-Za-z0-9_\\]+)(?:\s+as\s+\w+)?\s*;', re.M)
RE_FQN = re.compile(r'\\?(Source\\(?:Services|Models|Controllers|Support|Core)\\[A-Za-z0-9_\\]+)')
RE_TABLE = re.compile(r'CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z0-9_]+)`?', re.I)
RE_ALTER = re.compile(r'ALTER\s+TABLE\s+`?([a-z0-9_]+)`?', re.I)


def rel(p: Path) -> str:
    return p.relative_to(ROOT).as_posix()


def read(p: Path) -> str:
    try:
        return p.read_text(encoding="utf-8", errors="replace")
    except OSError:
        return ""


def parse_routes() -> tuple[list[dict], dict[str, int]]:
    routes: list[dict] = []
    dynamic: dict[str, int] = {}
    for env, path in ROUTE_FILES.items():
        text = read(path)
        if not text:
            continue
        ns, group, dyn = "", "", 0
        for line_no, line in enumerate(text.splitlines(), 1):
            m = RE_NS.search(line)
            if m:
                ns = (m.group(2) or m.group(3) or "").replace("\\\\", "\\")
            m = RE_GROUP.search(line)
            if m:
                raw = m.group(1)
                group = "{base}" if raw.startswith("$") else (m.group(2) or m.group(3) or "")
            for m in RE_ROUTE.finditer(line):
                verb, uri = m.group(1).upper(), m.group(3)
                ctrl, method = m.group(5), m.group(6)
                full = (group.rstrip("/") + "/" + uri.lstrip("/")).rstrip("/") or "/"
                if env == "erp" and group == "{base}":
                    full = "/erp" + (("/" + uri.lstrip("/")).rstrip("/") or "")
                fqcn = (ns + "\\" + ctrl) if (ctrl and ns) else None
                routes.append({
                    "env": env, "verb": verb, "path": full or "/",
                    "controller": fqcn, "method": method,
                    "file": rel(path), "line": line_no,
                })
            if RE_DYN.search(line):
                dyn += 1
        if dyn:
            dynamic[env] = dyn
    return routes, dynamic


def parse_classes() -> dict[str, dict]:
    classes: dict[str, dict] = {}
    for p in sorted((ROOT / "source").rglob("*.php")):
        text = read(p)
        ns = RE_NAMESPACE.search(text)
        cl = RE_CLASS.search(text)
        if not (ns and cl):
            continue
        fqcn = ns.group(1) + "\\" + cl.group(2)
        deps = set(RE_USE.findall(text)) | {d.rstrip("\\") for d in RE_FQN.findall(text)}
        deps.discard(fqcn)
        layer = fqcn.split("\\")[1] if fqcn.count("\\") >= 1 else "Other"
        classes[fqcn] = {
            "file": rel(p), "kind": cl.group(1), "layer": layer,
            "deps": sorted(deps), "tables": [], "_text": text,
        }
    return classes


def parse_tables() -> dict[str, dict]:
    tables: dict[str, dict] = {}
    sources = sorted((ROOT / "database" / "migrations").glob("*.sql"))
    sources += sorted((ROOT / "database" / "legacy-migrations").rglob("*.sql"))
    for p in sources:
        if p.name.endswith(".down.sql"):
            continue
        text = read(p)
        for t in RE_TABLE.findall(text):
            tables.setdefault(t, {"created_in": rel(p), "altered_in": [], "used_by": []})
        for t in RE_ALTER.findall(text):
            tables.setdefault(t, {"created_in": None, "altered_in": [], "used_by": []})
            if rel(p) not in tables[t]["altered_in"]:
                tables[t]["altered_in"].append(rel(p))
    return tables


def link_tables(classes: dict[str, dict], tables: dict[str, dict]) -> None:
    names = sorted(tables, key=len, reverse=True)
    for fqcn, info in classes.items():
        text = info["_text"]
        for t in names:
            if re.search(r'[`"\' ]' + re.escape(t) + r'[`"\' ]', text):
                info["tables"].append(t)
                tables[t]["used_by"].append(fqcn)


def parse_tests(classes: dict[str, dict]) -> dict[str, list[str]]:
    tests: dict[str, list[str]] = {}
    short = defaultdict(list)
    for fqcn in classes:
        short[fqcn.rsplit("\\", 1)[-1]].append(fqcn)
    for p in sorted((ROOT / "tests").rglob("*.php")):
        text = read(p)
        hits = set(d.rstrip("\\") for d in RE_FQN.findall(text)) | set(RE_USE.findall(text))
        tests[rel(p)] = sorted(h for h in hits if h in classes)
    return tests


def parse_adrs() -> list[dict]:
    adrs = []
    for d in (ROOT / "docs" / "Organic Code" / "decisions", ROOT / "docs" / "decisions"):
        for p in sorted(d.glob("*.md")):
            if p.name.lower() == "readme.md":
                continue
            first = next((l for l in read(p).splitlines() if l.startswith("#")), p.stem)
            adrs.append({"file": rel(p), "title": first.lstrip("# ").strip()})
    return adrs


def parse_erp_pages() -> list[dict]:
    p = ROOT / "docs" / "specs" / "routes.json"
    try:
        data = json.loads(read(p))
    except json.JSONDecodeError:
        return []
    keep = ("id", "name", "domain", "status", "spec_path")
    return [{k: page.get(k) for k in keep} for page in data if isinstance(page, dict)]


def build() -> dict:
    routes, dynamic = parse_routes()
    classes = parse_classes()
    tables = parse_tables()
    link_tables(classes, tables)
    tests = parse_tests(classes)
    tested = defaultdict(list)
    for t, cls in tests.items():
        for c in cls:
            tested[c].append(t)
    for fqcn, info in classes.items():
        info["deps"] = [d for d in info["deps"] if d in classes]
        info["tests"] = sorted(tested.get(fqcn, []))
        info.pop("_text", None)
    for r in routes:
        if r["controller"] and r["controller"] not in classes:
            r["controller_missing"] = True
    return {
        "generated_by": "tools/code-map.py",
        "counts": {
            "routes": len(routes), "dynamic_route_lines": dynamic, "classes": len(classes),
            "tables": len(tables), "tests": len(tests), "adrs": 0, "erp_pages": 0,
        },
        "routes": routes,
        "classes": classes,
        "tables": tables,
        "tests": tests,
        "adrs": parse_adrs(),
        "erp_pages": parse_erp_pages(),
    }


def mid(name: str) -> str:
    return re.sub(r"[^A-Za-z0-9_]", "_", name)


def short(fqcn: str) -> str:
    return fqcn.replace("Source\\", "").replace("\\", "/")


def render_md(m: dict) -> str:
    m["counts"]["adrs"] = len(m["adrs"])
    m["counts"]["erp_pages"] = len(m["erp_pages"])
    c = m["counts"]
    L: list[str] = []
    L.append("# Mapa do código do Connect ERP")
    L.append("")
    L.append("> Gerado por `python3 tools/code-map.py` (ADR 0016). Não edite à mão: rode o script de novo.")
    L.append("> Para procurar algo no terminal: `python3 tools/code-map.py --find condominiums`.")
    L.append("")
    L.append(f"**{c['routes']}** rotas fixas · **{c['classes']}** classes em `source/` · **{c['tables']}** tabelas nas migrations · "
             f"**{c['tests']}** arquivos de teste · **{c['adrs']}** ADRs · **{c['erp_pages']}** páginas no catálogo do ERP.")
    if c["dynamic_route_lines"]:
        dyn = ", ".join(f"{k}: {v}" for k, v in sorted(c["dynamic_route_lines"].items()))
        L.append(f"Rotas geradas em laço (cadastros do ERP, catálogo) não entram na contagem; linhas dinâmicas por ambiente: {dyn}.")
    L.append("")
    L.append("Sumário: [Visão geral](#visão-geral) · [ERP](#erp-controllers-services-e-tabelas) · [Rotas](#rotas) · "
             "[Tabelas](#tabelas) · [Classes](#classes) · [Testes](#testes) · [Decisões](#decisões) · [Tarefas](#tarefas)")
    L.append("")

    # Visão geral: ambiente -> camada de controllers
    L.append("## Visão geral")
    L.append("")
    L.append("```mermaid")
    L.append("flowchart LR")
    env_ctrl = defaultdict(set)
    for r in m["routes"]:
        if r["controller"]:
            parts = r["controller"].split("\\")
            env_ctrl[r["env"]].add("/".join(parts[1:-1]))
    for env in sorted(env_ctrl):
        L.append(f'  env_{mid(env)}(["{env}"])')
        for pkg in sorted(env_ctrl[env]):
            L.append(f'  env_{mid(env)} --> pkg_{mid(pkg)}["{pkg}"]')
    layers = defaultdict(int)
    for info in m["classes"].values():
        layers[info["layer"]] += 1
    L.append('  pkg_svc["Services"]:::svc')
    L.append('  pkg_mdl["Models"]:::svc')
    L.append('  db[("MariaDB: ' + str(c["tables"]) + ' tabelas")]')
    for pkg in sorted({p for pkgs in env_ctrl.values() for p in pkgs}):
        L.append(f"  pkg_{mid(pkg)} -.-> pkg_svc")
    L.append("  pkg_svc --> db")
    L.append("  pkg_mdl --> db")
    L.append("  classDef svc fill:#e8f5ee,stroke:#07884c")
    L.append("```")
    L.append("")

    # ERP: controllers -> services -> tables
    L.append("## ERP: controllers, services e tabelas")
    L.append("")
    L.append("```mermaid")
    L.append("flowchart LR")
    erp_ctrls = sorted(k for k in m["classes"] if k.startswith("Source\\Controllers\\Erp\\Connect\\") or k.startswith("Source\\Controllers\\Api\\V1\\"))
    seen_nodes = set()

    def node(fq: str, shape: str = "box") -> str:
        n = mid(fq)
        if n not in seen_nodes:
            seen_nodes.add(n)
            label = short(fq)
            L.append(f'  {n}["{label}"]' if shape == "box" else f'  {n}[("{label}")]')
        return n

    for ctrl in erp_ctrls:
        a = node(ctrl)
        for dep in m["classes"][ctrl]["deps"]:
            if "\\Services\\" in dep:
                b = node(dep)
                L.append(f"  {a} --> {b}")
    for fq, info in sorted(m["classes"].items()):
        if "\\Services\\" in fq and mid(fq) in seen_nodes:
            for t in sorted(set(info["tables"])):
                tn = "t_" + mid(t)
                if tn not in seen_nodes:
                    seen_nodes.add(tn)
                    L.append(f'  {tn}[("{t}")]')
                L.append(f"  {mid(fq)} --> {tn}")
    L.append("```")
    L.append("")

    # Rotas
    L.append("## Rotas")
    L.append("")
    by_env = defaultdict(list)
    for r in m["routes"]:
        by_env[r["env"]].append(r)
    for env in sorted(by_env):
        L.append(f"<details><summary><b>{env}</b> ({len(by_env[env])} rotas)</summary>")
        L.append("")
        L.append("| Verbo | Caminho | Controller | Onde |")
        L.append("|---|---|---|---|")
        for r in by_env[env]:
            ctrl = f"`{short(r['controller'])}::{r['method']}`" if r["controller"] else "closure"
            if r.get("controller_missing"):
                ctrl += " ⚠ não encontrado"
            L.append(f"| {r['verb']} | `{r['path']}` | {ctrl} | [{r['file']}:{r['line']}](../../{r['file']}#L{r['line']}) |")
        L.append("")
        L.append("</details>")
        L.append("")

    # Tabelas
    L.append("## Tabelas")
    L.append("")
    L.append("| Tabela | Criada em | Alterada em | Usada por |")
    L.append("|---|---|---|---|")
    for t, info in sorted(m["tables"].items()):
        created = f"`{Path(info['created_in']).name}`" if info["created_in"] else "(legado)"
        altered = ", ".join(f"`{Path(a).name}`" for a in info["altered_in"]) or "—"
        used = ", ".join(f"`{short(u)}`" for u in sorted(set(info["used_by"]))) or "—"
        L.append(f"| `{t}` | {created} | {altered} | {used} |")
    L.append("")

    # Classes
    L.append("## Classes")
    L.append("")
    by_layer = defaultdict(list)
    for fq, info in m["classes"].items():
        by_layer[info["layer"]].append((fq, info))
    for layer in sorted(by_layer):
        items = sorted(by_layer[layer])
        L.append(f"<details><summary><b>{layer}</b> ({len(items)})</summary>")
        L.append("")
        L.append("| Classe | Usa | Tabelas | Testes |")
        L.append("|---|---|---|---|")
        for fq, info in items:
            uses = ", ".join(short(d).split("/")[-1] for d in info["deps"][:8]) + (" …" if len(info["deps"]) > 8 else "")
            tbl = ", ".join(sorted(set(info["tables"]))) or "—"
            tst = str(len(info["tests"])) if info["tests"] else "—"
            L.append(f"| [`{short(fq)}`](../../{info['file']}) | {uses or '—'} | {tbl} | {tst} |")
        L.append("")
        L.append("</details>")
        L.append("")

    # Testes
    L.append("## Testes")
    L.append("")
    L.append("Rodar só com `composer test:unit:offline`, `composer test:unit` ou `composer test:integration` (nunca `vendor/bin/phpunit` direto).")
    L.append("")
    untested = sorted(fq for fq, i in m["classes"].items() if not i["tests"] and i["layer"] in ("Services",) and i["kind"] == "class")
    if untested:
        L.append(f"Services sem teste que os cite ({len(untested)}): " + ", ".join(f"`{short(u)}`" for u in untested))
        L.append("")

    # ADRs
    L.append("## Decisões")
    L.append("")
    for a in m["adrs"]:
        L.append(f"- [{a['title']}](../../{a['file'].replace(' ', '%20')})")
    L.append("")

    # Tarefas
    L.append("## Tarefas")
    L.append("")
    L.append("A fila de trabalho são as issues abertas de [djalmamartins/connect-erp](https://github.com/djalmamartins/connect-erp/issues). "
             "As que dependem do Djalma têm o rótulo `precisa-djalma`; o vigia de 30 minutos (`tools/claude/vigia/`) pula essas e retoma as outras.")
    L.append("")
    return "\n".join(L).rstrip() + "\n"


def write(m: dict) -> dict[Path, str]:
    md = render_md(m)
    js = json.dumps(m, ensure_ascii=False, indent=1, sort_keys=True) + "\n"
    return {OUT / "README.md": md, OUT / "code-map.json": js}


def find(m: dict, q: str) -> int:
    ql = q.lower()
    found = 0
    for r in m["routes"]:
        if ql in r["path"].lower() or (r["controller"] and ql in r["controller"].lower()):
            print(f"rota   {r['env']:8} {r['verb']:6} {r['path']}  ->  {short(r['controller']) + '::' + r['method'] if r['controller'] else 'closure'}  ({r['file']}:{r['line']})")
            found += 1
    for fq, i in sorted(m["classes"].items()):
        if ql in fq.lower() or ql in i["file"].lower():
            print(f"classe {short(fq)}  ({i['file']})")
            if i["deps"]:
                print("       usa: " + ", ".join(short(d) for d in i["deps"]))
            if i["tables"]:
                print("       tabelas: " + ", ".join(sorted(set(i["tables"]))))
            if i["tests"]:
                print("       testes: " + ", ".join(i["tests"]))
            found += 1
    for t, i in sorted(m["tables"].items()):
        if ql in t:
            print(f"tabela {t}  criada em {i['created_in'] or '(legado)'}; usada por: {', '.join(short(u) for u in sorted(set(i['used_by']))) or '—'}")
            found += 1
    for a in m["adrs"]:
        if ql in a["title"].lower() or ql in a["file"].lower():
            print(f"adr    {a['title']}  ({a['file']})")
            found += 1
    for p in m["erp_pages"]:
        if ql in (p.get("id") or "").lower() or ql in (p.get("name") or "").lower():
            print(f"página {p['id']} {p['name']} [{p.get('status')}]  {p.get('spec_path') or ''}")
            found += 1
    if not found:
        print(f"Nada encontrado para '{q}'.")
    return 0 if found else 1


def main(argv: list[str]) -> int:
    m = build()
    if "--find" in argv:
        i = argv.index("--find")
        if i + 1 >= len(argv):
            print("Uso: --find <texto>", file=sys.stderr)
            return 2
        return find(m, argv[i + 1])
    files = write(m)
    if "--check" in argv:
        stale = [rel(p) for p, content in files.items() if read(p) != content]
        if stale:
            print("Mapa desatualizado: " + ", ".join(stale) + ". Rode: python3 tools/code-map.py", file=sys.stderr)
            return 1
        print("Mapa em dia.")
        return 0
    OUT.mkdir(parents=True, exist_ok=True)
    for p, content in files.items():
        p.write_text(content, encoding="utf-8")
    c = m["counts"]
    print(f"docs/map/ atualizado: {c['routes']} rotas, {c['classes']} classes, {c['tables']} tabelas, {c['tests']} testes, {c['adrs']} ADRs.")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
