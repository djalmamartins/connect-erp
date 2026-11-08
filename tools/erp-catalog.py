#!/usr/bin/env python3
"""Gera o catálogo validado de páginas do Connect ERP v1 a partir de docs/specs/routes.json.

Uso: python3 tools/erp-catalog.py
Saída: container/erp/connect/v1/components/layout/catalog.php e docs/ERP_V1_ROUTE_MATRIX.md

As decisões de validação (remoções, fusões, reinterpretações, agrupamento) ficam
explícitas nas tabelas abaixo para que a matriz seja reproduzível.
"""
import json, re, unicodedata, pathlib

ROOT = pathlib.Path(__file__).resolve().parent.parent
SPECS = json.loads((ROOT / "docs/specs/routes.json").read_text(encoding="utf-8"))

# Rotas removidas do ERP (fornecedor SaaS, ADR-002) ou fundidas em outra (duplicadas).
REMOVED = {
    "AP-169": "Fora do escopo: assinatura do fornecedor SaaS (ADR-002)",
    "AP-170": "Fora do escopo: suporte do fornecedor SaaS; Connect usa o próprio Help Desk",
    "AP-172": "Fora do escopo: notas de versão do fornecedor SaaS",
}
MERGED = {
    "AP-055": ("AP-052", "Duplicada de AP-052 (mesmo nome e rota de referência)"),
    "AP-077": ("AP-051", "Duplicada de AP-051 (Demonstrativo)"),
    "AP-078": ("AP-050", "Duplicada de AP-050 (Comparativo)"),
}
RENAMES = {
    "AP-001": "Dashboard",
    "AP-002": "Painel do condomínio",
    "AP-003": "Painel da unidade",
    "AP-005": "Proprietários e inquilinos",
    "AP-014": "Síndico e conselho",
    "AP-020": "Lançamentos",
    "AP-021": "Lançamentos fixos",
    "AP-059": "Inadimplentes (Detalhado)",
    "AP-082": "Demonstração do resultado",
    "AP-088": "Listagem de presenças",
    "AP-094": "Listagem de prestações de contas",
    "AP-148": "Usuários de imobiliárias",
    "AP-151": "Notificações da prestação de contas",
    "AP-152": "Criação de usuários para moradores",
    "AP-161": "Saldo inicial (plano de contas)",
    "AP-162": "Saldo inicial (centro de custo)",
    "AP-171": "Integrações",
    # Gráficos têm nomes iguais a relatórios; o prefixo evita rotas e rótulos repetidos.
    "AP-102": "Gráficos gerais",
    "AP-103": "Gráfico de contas",
    "AP-104": "Gráfico de consumo (unidade)",
    "AP-105": "Gráfico de consumo (geral)",
    "AP-106": "Gráfico financeiro",
    "AP-107": "Gráfico de inadimplência",
    "AP-108": "Gráfico financeiro por conta",
    "AP-109": "Gráfico de áreas comuns",
    "AP-047": "Relatório de acordos",
    "AP-070": "Relatório de boletos",
    "AP-068": "Relatório de lançamentos",
    "AP-069": "Relatório de lançamentos (detalhado)",
    "AP-089": "Relatório de condomínios",
    "AP-090": "Relatório de ocorrências",
    "AP-086": "Relatório de unidades",
    "AP-085": "Relatório de reservas",
    "AP-084": "Relatório de previsão orçamentária",
    "AP-049": "Relatório de centros de custo",
    "AP-101": "Prestação de contas",
}
REINTERPRET = {
    "AP-001": "Dashboard interno da administradora Connect; não existe cadastro de administradoras",
    "AP-148": "Acesso de imobiliárias parceiras aos condomínios administrados pela Connect",
    "AP-171": "Integrações da própria Connect (bancos, e-mail), não da plataforma do fornecedor",
}

# Grupos da sidebar, na ordem do menu: chave, rótulo, ícone, prefixo da rota.
GROUPS = [
    ("dashboard", "Dashboard", "home", ""),
    ("condominios", "Condomínios", "building", "/condominios"),
    ("cadastros", "Cadastros", "users", "/cadastros"),
    ("financeiro", "Financeiro", "wallet", "/financeiro"),
    ("cobranca", "Cobrança", "receipt", "/cobranca"),
    ("prestacao-contas", "Prestação de contas", "scale", "/prestacao-de-contas"),
    ("operacional", "Operacional", "clipboard", "/operacional"),
    ("comunicacao", "Comunicação", "megaphone", "/comunicacao"),
    ("documentos", "Documentos", "file", "/documentos"),
    ("relatorios", "Relatórios", "chart", "/relatorios"),
    ("importacoes", "Importações", "upload", "/importacoes"),
    ("configuracoes", "Configurações", "settings", "/configuracoes"),
]

def ids(*items):
    out = []
    for item in items:
        if isinstance(item, tuple):
            out += [f"AP-{n:03d}" for n in range(item[0], item[1] + 1)]
        else:
            out.append(f"AP-{item:03d}")
    return out

GROUP_OF = {}
for key, members in {
    "dashboard": ids(1),
    "condominios": ids(4, 2, 3, 6, 8, 7, 19),
    # Síndico e conselho (AP-014) fica em Cadastros por decisão do Djalma (08/11/2026).
    "cadastros": ids(5, 14, 9, 10, 16, 11, 12, 13, 15),
    "financeiro": ids(20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 40),
    "cobranca": ids(117, 33, 34, 35, 37, 36, 38, 39, 116),
    "prestacao-contas": ids(101, 94, 51, 52, 53, 54, 56, 79, 80, 81, 82, 83),
    "operacional": ids(110, 112, 113, 114, 115, 118, 119, 120, 121, 41, 42, 43, (131, 136)),
    "comunicacao": ids(111, 18, 17, 137, 138, 139),
    "documentos": ids((122, 130)),
    "relatorios": ids((102, 109), (45, 50), (57, 76), 84, (85, 93), (95, 100)),
    "importacoes": ids(44, (140, 146)),
    "configuracoes": ids(147, 149, 150, 153, 148, 152, 151, 154, 155, 156, 159, 157, 158, (160, 168), 171),
}.items():
    for order, ap in enumerate(members):
        GROUP_OF[ap] = (key, order)

# Subgrupos visuais dentro de grupos longos (cabeçalhos do submenu).
SECTION = {}
for ap in ids((102, 109)): SECTION[ap] = "Gráficos"
for ap in ids((45, 50), (57, 76), 84): SECTION[ap] = "Financeiros"
for ap in ids((85, 93), (95, 100)): SECTION[ap] = "Gerais"
for ap in ids((131, 136)): SECTION[ap] = "Portaria"
for ap in ids(41, 42, 43): SECTION[ap] = "Leituras e consumo"
for ap in ids(110, 112, 113, 114, 115, 118, 119, 120, 121): SECTION[ap] = "Rotina"
for ap in ids(137, 138, 139): SECTION[ap] = "Mala direta"
for ap in ids(111, 18, 17): SECTION[ap] = "Canais"
for ap in ids(147, 149, 150, 153, 148, 152): SECTION[ap] = "Acesso"
for ap in ids(151, 154, 155, 156, 159, 171): SECTION[ap] = "Aplicativo e notificações"
for ap in ids(157, 158, (160, 168)): SECTION[ap] = "Financeiro e parâmetros"

def kind(ap, name, domain):
    n = name.lower()
    if domain == "dashboards" and ap in ("AP-001", "AP-002", "AP-003"): return "dashboard"
    if domain == "dashboards": return "chart"
    if domain == "relatorios" or ap in ("AP-079",): return "report"
    if domain == "importacoes" or ap == "AP-036": return "import"
    if n.startswith("lançar") or ap in ("AP-029", "AP-152"): return "form"
    if ap == "AP-030": return "closing"
    if domain == "configuracoes" and ap not in ("AP-149", "AP-148", "AP-156", "AP-160", "AP-168", "AP-165", "AP-164"): return "settings"
    if ap in ("AP-123", "AP-124", "AP-125", "AP-130"): return "document"
    return "list"

def slug(text):
    text = unicodedata.normalize("NFKD", text).encode("ascii", "ignore").decode()
    text = re.sub(r"[^a-zA-Z0-9]+", "-", text.lower()).strip("-")
    return text

# Rotas fixas (contrato com outras frentes e URLs curtas).
FIXED_ROUTE = {"AP-001": "/dashboard", "AP-004": "/condominios"}

pages, matrix = [], []
for spec in SPECS:
    ap = spec["id"]
    name = RENAMES.get(ap, spec["name"]).strip()
    if ap in REMOVED:
        matrix.append((ap, spec["name"], "—", "—", "REMOVIDA", REMOVED[ap]))
        continue
    if ap in MERGED:
        target, why = MERGED[ap]
        matrix.append((ap, spec["name"], "—", "—", "FUNDIDA", f"{why} → {target}"))
        continue
    group, order = GROUP_OF[ap]
    gprefix = next(g[3] for g in GROUPS if g[0] == group)
    route = FIXED_ROUTE.get(ap, f"{gprefix}/{slug(name)}")
    pages.append({
        "id": ap, "name": name, "group": group, "section": SECTION.get(ap, ""), "order": order,
        "route": route, "kind": kind(ap, name, spec["domain"]),
        "status": spec["status"], "spec": spec["spec_path"], "reference": spec["reference_route"],
        "note": REINTERPRET.get(ap, ""),
    })
    matrix.append((ap, spec["name"], name, "/erp" + route, "VALIDADA" if ap not in REINTERPRET else "REINTERPRETADA", REINTERPRET.get(ap, "")))

# Páginas próprias da Connect, sem rota de referência no inventário APControle.
CONNECT_PAGES = [
    {"id": "CN-001", "name": "Vistorias", "group": "operacional", "section": "Rotina", "order": -1,
     "route": "/operacional/vistorias", "kind": "list", "status": "CONNECT",
     "spec": "docs/specs/pages/cn-001-vistorias.md", "reference": "",
     "note": "Vistoria presencial com check-in por GPS, checklist, fotos e relatório (pedido do proprietário, 08/11/2026)"},
]
for p in CONNECT_PAGES:
    pages.append(p)
    matrix.append((p["id"], p["name"], p["name"], "/erp" + p["route"], "CONNECT", p["note"]))

routes = [p["route"] for p in pages]
dupes = {r for r in routes if routes.count(r) > 1}
assert not dupes, f"rotas duplicadas: {dupes}"
assert all(ap in GROUP_OF or ap in REMOVED or ap in MERGED for ap in (s["id"] for s in SPECS))
pages.sort(key=lambda p: ([g[0] for g in GROUPS].index(p["group"]), p["order"]))

def php(v):
    if isinstance(v, str): return "'" + v.replace("\\", "\\\\").replace("'", "\\'") + "'"
    if isinstance(v, int): return str(v)
    raise TypeError(v)

lines = ["<?php", "", "declare(strict_types=1);", "",
         "/**", " * Catálogo validado de páginas do Connect ERP v1.",
         " * GERADO por tools/erp-catalog.py a partir de docs/specs/routes.json: não editar à mão.",
         " * Matriz e decisões de validação: docs/ERP_V1_ROUTE_MATRIX.md.", " */", "", "return [", "    'groups' => ["]
for key, label, icon, prefix in GROUPS:
    lines.append(f"        {php(key)} => ['label' => {php(label)}, 'icon' => {php(icon)}, 'prefix' => {php(prefix)}],")
lines += ["    ],", "    'pages' => ["]
for p in pages:
    fields = ", ".join(f"{php(k)} => {php(p[k])}" for k in ("name", "group", "section", "route", "kind", "status", "spec", "reference", "note"))
    lines.append(f"        {php(p['id'])} => [{fields}],")
lines += ["    ],", "];", ""]
out = ROOT / "container/erp/connect/v1/components/layout/catalog.php"
out.parent.mkdir(parents=True, exist_ok=True)
out.write_text("\n".join(lines), encoding="utf-8")

md = ["# Connect ERP v1 — matriz de rotas validada", "",
      "Gerada por `tools/erp-catalog.py` a partir de `docs/specs/routes.json` (inventário de 172 rotas da auditoria APControle).",
      "As rotas de referência do APControle não são rotas do Connect ERP: cada página recebeu uma rota própria em `/erp`.", "",
      f"- Inventário: {len(SPECS)} · Validadas: {len(pages)} · Removidas: {len(REMOVED)} · Fundidas: {len(MERGED)}", "",
      "Componente: template que renderiza a rota hoje. Implementação: `interface proposta (demonstração)` = rota registrada e protegida, com a interface do seu tipo, colunas e campos propostos (a confirmar nas fichas) e dados fictícios; operações de negócio ficam pendentes até o backend. Teste: resultado de `tests/erp/render-smoke.php`.", "",
      "| AP | Nome no inventário | Página | Rota | Grupo | Tipo de interface | Componente | Layout | Ações visuais | Validação | Implementação | Teste | Observação |",
      "|---|---|---|---|---|---|---|---|---|---|---|---|---|"]
by_id = {p["id"]: p for p in pages}
for ap, orig, name, route, status, note in matrix:
    p = by_id.get(ap)
    group = next((g[1] for g in GROUPS if p and g[0] == p["group"]), "—")
    if p and p["id"] == "AP-001":
        comp, actions, impl = "pages/dashboard/index", "período, exportar (pendente), comunicado rápido (modal), filtro/ordenação/paginação", "completa (demonstração)"
    elif p and p["id"] == "CN-001":
        comp, actions, impl = "pages/inspections/*", "check-in GPS, checklist, fotos, concluir, relatório HTML/PDF, envio por e-mail, configurar modelos/planos/localização", "completa (dados reais)"
    elif p:
        comp = "pages/modules/" + p["kind"]
        actions = {
            "list": "filtros, ordenação, paginação, cadastro/edição (modal), detalhe com abas, exclusão com confirmação",
            "report": "parâmetros, filtro, ordenação, paginação, totais, exportar (pendente), imprimir",
            "form": "validação, resumo ao vivo, limpar, confirmar (pendente)",
            "import": "etapas, validação de arquivo, pré-visualização, importar (pendente)",
            "settings": "seções ou matriz de permissões, salvar (pendente), histórico",
            "document": "parâmetros, prévia, emitir (pendente)",
            "chart": "parâmetros, gráfico com tabela acessível, exportar (pendente)",
            "closing": "competência, checklist, resumo, fechar (pendente)",
            "dashboard": "contexto, indicadores, gráfico, tabela",
        }[p["kind"]]
        impl = "interface proposta (demonstração)"
    else:
        comp = actions = impl = "—"
    test = "smoke OK" if p else "—"
    md.append(f"| {ap} | {orig} | {name} | `{route}` | {group} | {p['kind'] if p else '—'} | {comp} | {'default.php' if p else '—'} | {actions} | {status} | {impl} | {test} | {note} |")
(ROOT / "docs/ERP_V1_ROUTE_MATRIX.md").write_text("\n".join(md) + "\n", encoding="utf-8")
print(f"{len(pages)} páginas, {len(REMOVED)} removidas, {len(MERGED)} fundidas")
