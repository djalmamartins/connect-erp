# 0038 — Telas da cobrança bancária (boletos, importar retorno, divergências)

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

O backend bancário (ADR 0035) expõe `/api/v1/bank-charges`, `/api/v1/bank-returns` e
`/api/v1/bank-events/divergences|{id}/resolve`, só com o adaptador de sandbox. As páginas AP-035
(Boletos), AP-036 (Arquivo de Retorno) e AP-038 (Retornos) mostravam só a demonstração.

## Decisão

- `Panel::bankingData` carrega os dados no contexto da barra superior, com
  `erp.bank_charges.view`; `canManageCharges` vem de `erp.bank_charges.manage`. Sem permissão ou
  sem banco, a página segue com a demonstração. `Panel::ownTemplate` escolhe a tela própria.
- **AP-035 Boletos** (`pages/finance/bank-charges.php`): lista com filtro por situação e
  paginação; emitir (conta a receber válida e em aberto + conta bancária do condomínio, carregadas
  pela API de cadastros); consultar e cancelar só em "aguardando banco" ou "registrado"; copiar a
  linha digitável.
- **AP-036 Arquivo de Retorno** (`pages/finance/bank-return-import.php`): o navegador lê o arquivo
  como texto (até 2 MB) e envia em JSON; o resultado mostra o resumo e cada ocorrência.
- **AP-038 Retornos** (`pages/finance/bank-divergences.php`): fila de divergências, com "Marcar como
  revisada" e nota de 5 a 255 caracteres. A correção continua manual.
- Toda tela avisa que é ambiente de testes (sandbox) e que nada é pagável.
- O JavaScript (`erp.js`) reaproveita um cliente único da API (`apiClient`, CSRF no cabeçalho),
  agora usado também pelas faturas.

## Consequências e lacunas

- AP-038 passou a listar também os arquivos importados quando a API ganhou `GET /bank-returns` (ADR 0040).
- AP-037 (Remessas) segue na demonstração: remessa CNAB fica para o primeiro banco homologado.
- A lista de boletos mostra o número do lançamento, não a descrição nem a unidade (a API não traz).
