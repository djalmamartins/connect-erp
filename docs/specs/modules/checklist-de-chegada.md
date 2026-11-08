# Checklist de chegada do condomínio

Origem: pedido do Djalma em 08/11/2026 ("quando ele chega, tudo que precisa, tudo que falta
para fazer… gerar protocolos para a gente poder consertar e manter tudo em dia"). Não é uma
rota do APControle: é funcionalidade própria do Connect ERP. Decisão técnica: ADR 0013.

## Objetivo

Quando a Connect assume um condomínio, verificar item a item o que existe, o que falta e o
que vence. O que falta vira **protocolo** com prazo e responsável; o que está em dia guarda a
**validade** e volta a pedir atenção antes de vencer.

## Situações de um item

| Status gravado | Rótulo | Efeito |
|---|---|---|
| `pending` | A verificar | Estado inicial. |
| `regular` | Em dia | Fecha o item. Sem validade informada, usa a periodicidade do catálogo. |
| `action_required` | Falta providenciar | Gera protocolo `CHK-AAAA-NNNNNN` (uma vez) e prazo padrão do item. |
| `in_progress` | Em andamento | Mantém o protocolo; também gera se ainda não houver. |
| `not_applicable` | Não se aplica | Fecha o item (ex.: prédio sem elevador). |

Situações calculadas na leitura (não gravadas): `expiring` (em dia, vence em até 30 dias),
`expired` (validade passou), `overdue` (protocolo aberto com prazo vencido).

## Catálogo inicial (configurável)

Tabela `onboarding_item_templates`. Prazo = dias para regularizar; Renovação = meses até vencer.

| Categoria | Item | Prazo | Renovação |
|---|---|---|---|
| Documentação | CNPJ do condomínio (tem ou precisa abrir) | 30 | — |
| Documentação | Convenção registrada em cartório | 60 | — |
| Documentação | Regimento interno | 60 | — |
| Documentação | Ata de eleição do síndico vigente | 30 | — |
| Documentação | Certificado digital e-CNPJ | 15 | 12 |
| Documentação | Contrato de administração com a Connect | 15 | — |
| Segurança e Bombeiros | AVCB / CLCB do Corpo de Bombeiros | 90 | informar validade |
| Segurança e Bombeiros | Extintores: recarga e inspeção | 30 | 12 |
| Segurança e Bombeiros | Mangueiras e hidrantes: inspeção | 30 | 12 |
| Segurança e Bombeiros | SPDA (para-raios): laudo | 60 | 12 |
| Segurança e Bombeiros | Brigada de incêndio | 60 | 12 |
| Manutenção | Elevadores: contrato de manutenção | 30 | 12 |
| Manutenção | Limpeza da caixa d'água | 30 | 6 |
| Manutenção | Dedetização e desratização | 30 | 6 |
| Seguros | Seguro obrigatório da edificação (CC art. 1.346) | 15 | 12 |
| Financeiro e fiscal | Conta bancária em nome do condomínio | 30 | — |
| Financeiro e fiscal | Prestação de contas da gestão anterior | 30 | — |
| Financeiro e fiscal | Levantamento de inadimplência | 30 | — |
| Financeiro e fiscal | Certidões negativas (Receita e FGTS) | 30 | 6 |
| Funcionários | Funcionários registrados e eSocial em dia | 30 | — |
| Funcionários | PGR e PCMSO | 60 | 12 |

**INFERIDO:** prazos e periodicidades são valores de mercado para começar; a validade do AVCB
varia por estado e por porte da edificação, por isso o item não tem renovação automática e a
data deve ser informada. A Connect deve revisar a lista. "RCB" no pedido foi interpretado
como AVCB/CLCB.

## Telas e API

- `GET /erp/condominios/{id}/checklist` — resumo (percentual em ordem, a verificar, protocolos
  abertos, vencidos ou vencendo) e itens agrupados por categoria. Link a partir da ficha do condomínio.
- `POST /erp/condominios/{id}/checklist/iniciar` — cria os itens ativos do catálogo (idempotente;
  serve também para puxar itens novos do catálogo).
- `POST /erp/condominios/{id}/checklist/itens/{item}` — atualiza situação, prazo, validade,
  observações e responsável.
- `POST /erp/condominios/{id}/checklist/itens` — pendência avulsa, já com protocolo.
- API REST equivalente em `/api/v1/condominiums/{id}/onboarding` (ver `docs/api/openapi.yaml`).

## Regras

- Leitura: `erp.access` + `erp.condominiums.view`. Alteração: `erp.condominiums.manage` + CSRF.
- Escopo por condomínio no servidor (`CondominiumScope`); fora do escopo responde 404.
- Toda alteração gera `system_audit_logs` (entidade `condominium_onboarding_items`).
- Catálogo: permissão `erp.onboarding.catalog` criada; a tela de edição do catálogo é PENDENTE
  (por ora, ajuste por migration/SQL).

## PENDENTE

- Iniciar o checklist automaticamente ao cadastrar o condomínio (AP-004): chamar
  `OnboardingChecklist::start($id, $userId)` logo após o INSERT, na fatia de cadastro.
- Anexar documentos/comprovantes ao item (depende do módulo de arquivos).
- Aviso por e-mail/notificação de validade a vencer (rotina diária sobre `idx_coi_expires`).
- Ligação com a vistoria presencial (thread própria): achados da vistoria devem abrir itens
  avulsos aqui em vez de outra tabela de protocolos.
- Tela para editar o catálogo (`erp.onboarding.catalog`).
