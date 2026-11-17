# 0344 — XSS nas telas do ERP: texto gravado sai sempre escapado

- Status: Aceito
- Data: 17/11/2026
- Pedido: `docs/ROADMAP_V1.md` §3.7 (XSS); Djalma, 16/11/2026: "tome as melhores decisões"
- Relacionadas: ADR 0340–0343 (revisão de IDOR), `docs/security/SECURITY.md`

## Contexto

Uma varredura estática das views do ERP (`<?= ... ?>` sem `$this->e`) deu centenas de casos, quase todos números, ids,
rótulos fixos e ternários seguros. Ruído demais para servir de trava. Faltava uma prova de ponta a ponta: um texto com HTML
gravado no banco nunca pode sair cru numa tela.

## Decisão

`ErpScreensXssCanaryIntegrationTest` grava o texto `"'></script><i data-xss>x` em toda coluna de texto (que comporta o
tamanho) de cada tabela de cadastro, no nome do condomínio e no nome do usuário, e renderiza no servidor:

- a listagem e a ficha de cada um dos 42 cadastros;
- as 174 páginas do catálogo e as visões gerais dos grupos.

A marca crua `<i data-xss>` em qualquer resposta é falha. O texto fecha aspas e um `<script>`, então pega também saída em
atributo e dentro de script. Para o teste provar algo, cada listagem e ficha precisa mostrar o texto escapado mais vezes que a
moldura (barra do condomínio e usuário), e nenhuma tela pode quebrar com ele.

## Resultado

270 telas renderizadas, nenhuma com o texto cru. Para conferir que o teste pega a falha, o escape do nome do condomínio no
filtro da listagem de cadastros foi tirado por um instante: o teste falhou. O escape foi devolvido.

## Fora desta fatia

O que as telas montam no navegador a partir da API (JavaScript com `innerHTML`), as telas de detalhe fora do motor de
cadastros (vistoria, chamado, assembleia), o Help Desk, o site e o app do morador.
