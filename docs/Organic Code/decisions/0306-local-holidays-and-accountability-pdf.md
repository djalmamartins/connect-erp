# 0306 — Feriados do estado e da cidade no prazo da prestação de contas, PDF do livro e pacote com as notas

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:** ADR 0301 (link público da prestação de contas e prazo do 5º dia útil), 0174 (livro caixa em PDF e
  pacote), 0160 (antivírus no download), 0011 (cadastro do condomínio com cidade e UF)
- **Numeração:** faixa avulsa 0300–0319, próximo livre conferido na main, no claude-dev, nos branches e no handoff.

## Contexto

O ADR 0301 deixou duas lacunas marcadas como PENDENTE: o 5º dia útil contava só os feriados nacionais (e os dias sem
banco), e o livro anual não tinha PDF no servidor, só a impressão do navegador. O Djalma pediu para continuar o
trabalho (14/11/2026, "vamos continuar") e o pedido original fala em "gerar esse livro com notas" ao fechar o ano.

Não existe no repositório uma tabela oficial de feriados estaduais e municipais, e esses feriados mudam por lei local.
Colocar uma lista pronta seria afirmar como fato algo não conferido.

## Decisão

### Feriados locais (`LocalHolidays`, tabela `erp_local_holidays`, migration 20261114_2100)

- A equipe cadastra os feriados no **Livro-caixa**, cartão "Prazo e feriados locais", ou pela API
  `/api/v1/local-holidays`. O lugar vem do cadastro do condomínio (cidade e UF): um feriado **estadual** vale para
  todos os condomínios da UF; um **municipal**, para os da mesma cidade (comparada sem acento, maiúsculas e espaços
  extras).
- "Todo ano" guarda dia e mês (`year = 0`); "só neste ano" guarda também o ano (ponto facultativo decretado). 29/02
  só pode ser "só neste ano". Repetido no mesmo lugar = 409.
- Nenhuma lista pronta é semeada (INFERIDO seria afirmar feriados sem fonte). O artigo da Central de Ajuda explica
  como cadastrar.
- O prazo (`AccountabilityDeadline`) passa a ser **por condomínio**: as regras puras aceitam a lista de feriados locais
  e a rotina diária calcula o prazo uma vez por lugar. O resultado ganha `local_deadlines` (quantos condomínios têm
  prazo diferente do nacional). O aviso do sino mostra a data do prazo do lugar.
- Ver: erp.closing.view (API) ou o livro caixa (tela). Incluir e remover: erp.closing.manage, com CSRF na tela.
  Escopo por condomínio (ADR-002): pedir por um condomínio fora do escopo, ou remover o feriado de outro lugar, dá 404.
  Inclusão e remoção vão para a auditoria.

### PDF do livro (`AccountabilityBookDocument`, `GET /prestacao/{token}/pdf`)

- O mesmo livro congelado do link (mensal ou anual), em PDF pelo dompdf (sem acesso remoto, logo em data URI), com a
  identidade da Connect. No anual: resumo por categoria, tabela mês a mês e cada mês numa página.
- Cada documento sai com o endereço completo no link público: no PDF, clicar abre a nota, a conta ou o comprovante.
  Os documentos não entram dentro do PDF (o dompdf não junta PDFs, e o anual pode ter centenas de arquivos).
- Botão "Baixar PDF" na página pública e "PDF" no cartão do Livro-caixa. Mesmo tratamento do link: token inválido ou
  revogado = 404, sem referrer, fora dos buscadores, CSP com sandbox; limite de 20 PDFs a cada 10 minutos por sessão.
  O PDF fica guardado na pasta temporária por até 24 h, com nome pelo hash do token e do conteúdo (outra versão =
  outro arquivo; o nome não revela o token). Baixar o PDF não conta visita.

### Pacote com as notas (`GET /erp/prestacao-de-contas/livro-caixa/link/{id}/pacote`)

- Para a equipe: ZIP com o PDF e todos os documentos do livro, na ordem do livro (`documentos/001-comprovante-...`;
  no anual, uma pasta por mês: `documentos/10-outubro/...`). É o "livro do ano com as notas" para arquivar ou mandar
  ao conselho. Documento repetido entra uma vez.
- Só link ativo e do escopo de quem pede (erp.entries.view, como o pacote do mês do ADR 0174). Documentos removidos do
  Disco Virtual ou bloqueados pelo antivírus ficam de fora, listados em `documentos/LEIA-ME.txt`. Limite de 200 MB.
  O download vai para a auditoria (`export`).

### Correção

- `AccountabilityLinks::scoped()` criava `CondominiumAccessDenied` sem o id do condomínio (o construtor exige), então
  pedir o link de outro condomínio dava erro 500 em vez de 404. Agora passa o id.

## Consequências

- **Implantação.** Rodar a migration 20261114_2100 (o `testar-main.sh` aplica). O cron do ADR 0301 continua o mesmo.
- **Testes.** Unitários (`LocalHolidaysTest`: cidade normalizada, datas por ano, 29/02, prazo empurrado por feriado
  local, nomes e ordem do pacote, PDF mensal e anual com os links, rotas e segurança) e integração no MariaDB (feriado
  da cidade só vale na cidade, o do estado vale na UF, escopo, auditoria, rotina com prazos diferentes por condomínio,
  pacote com o PDF e os documentos na ordem, PDF sem contar visita, link revogado sem PDF).
- **Central de Ajuda.** Artigo do livro caixa com as seções do PDF, do pacote e dos feriados locais.
- **Riscos e lacunas (PENDENTE).**
  - Os feriados dependem de cadastro manual: sem cadastro, o prazo é o nacional (o aviso pode chegar um dia antes do
    necessário, nunca depois).
  - Cidade escrita de forma diferente no cadastro de dois condomínios (por exemplo, abreviada) não casa.
  - O PDF não traz as imagens das notas dentro dele; elas estão no link e no pacote.
