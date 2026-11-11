<!-- markdownlint-disable MD024 -->
# 0218 — Calendário de vencimentos do condomínio

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - ADR 0013 (checklist de chegada e avisos);
  - 0051 (seguros e contratos);
  - 0151 (síndico e conselho);
  - 0217 (renovação de contratos);
  - 0215 (mesmo formato de relatório).

## Contexto

Os vencimentos de um condomínio estão espalhados, um por tela:

- a apólice do seguro (AP-118);
- os contratos (AP-119);
- o mandato do síndico e do conselho (AP-014);
- os documentos do checklist de chegada: AVCB, laudos e o prazo dos protocolos (ADR 0013).

O painel operacional conta só os seguros e contratos que vencem em 30 dias. O fim do mandato não aparece em lugar
nenhum: nada no `source/` lê `term_ends_on`. Para a assembleia, o síndico e a administradora precisam ver tudo o que vence
nos próximos meses de uma vez.

## Decisão

Rota nova, somente leitura: `GET /api/v1/reports/obligation-calendar?condominium_id=&as_of=&days=30|60|90`. O service
é `ObligationCalendar`.

### Fontes

| Fonte | O que entra | Data | Permissão |
|---|---|---|---|
| `insurances` | apólices vigentes | fim da vigência | `erp.insurances.view` |
| `contracts` | contratos vigentes com fim | fim do contrato | `erp.contracts.view` |
| `board` | mandatos ativos | fim do mandato | `erp.board.view` |
| `checklist` | item em dia com validade; item em aberto com prazo de protocolo | validade ou prazo | `erp.condominiums.view` |

- **Prazo indeterminado:** contrato sem fim fica de fora.
- **Mandato:** sai só o cargo (`Mandato: Síndico`); nenhum nome.
- **Checklist:** reusa o `OnboardingChecklist::items`, a mesma leitura e situação da tela do checklist.
- **Fonte sem permissão:** fica de fora e vem listada em `hidden_sources`.

### Regra pura `bucket()`

| Faixa | Quando |
|---|---|
| `expired` | antes da data de referência |
| `30` | até 30 dias |
| `60` | até 60 dias |
| `90` | até 90 dias |

Os vencidos entram sempre. Os futuros entram até a janela `days` (padrão 90).

### Acesso e dados

- **Escopo (ADR-002):** um condomínio do escopo; fora dele, 404; entrada inválida, 422.
- **Permissão:** exige `erp.access` e ao menos uma fonte permitida; sem nenhuma, 403 (fail-closed).
- **Somente leitura:** não grava, não audita e não envia nada. Os avisos por e-mail do checklist continuam no
  `OnboardingAlerts`.
- **Dados que não saem:**
  - nome, CPF, e-mail e telefone dos membros do conselho;
  - responsável e observações dos itens do checklist.
- **Dados que saem:**
  - o objeto do contrato (até 120 caracteres) e o `supplier_id`;
  - a seguradora e o número da apólice.
- **Limite:** até 1000 itens (`truncated`).
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\ObligationCalendarTest`:**
  - as regras puras (dias e faixas);
  - seguros, contratos e mandatos no SQLite: vigente, cancelado, outro condomínio, prazo indeterminado e janela;
  - fontes ocultas;
  - nenhum dado pessoal no JSON;
  - validação e escopo (404);
  - contrato: somente leitura;
  - controller e rota.
- **`Integration\ObligationCalendarTest`** (MariaDB, banco `*_test`):
  - as quatro fontes criadas pelos formulários e pelo checklist (AVCB vencido);
  - a ordem e as faixas;
  - nenhum dado pessoal;
  - nenhuma linha nova na auditoria;
  - a janela de 30 dias;
  - fontes ocultas;
  - escopo.
- **Sem tela nova neste patch.** O painel operacional pode ganhar o cartão "Vencimentos"; quando ganhar, vem o artigo
  da Central de Ajuda.
