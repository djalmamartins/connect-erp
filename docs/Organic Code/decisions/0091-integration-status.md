# 0091 — Integrações: só o estado, sem credencial e sem ação

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Página: AP-171

## Contexto

Era a última página sem backend. A integração com banco real depende de homologação e aprovação
(ADR 0035), e o envio de e-mail depende do ambiente (ADR 0055). Mostrar o estado não depende de
nenhuma dessas decisões.

## Decisão

- `IntegrationStatus` e `GET /api/v1/integrations` são somente leitura e exigem `erp.settings.view`.
- **O que mostra:**
  - banco: os adaptadores disponíveis (hoje só o sandbox) e a nota de homologação;
  - e-mail: o modo efetivo (log, sandbox ou production, pela mesma regra do dispatcher), se há caixa de
    teste configurada e se o envio chega ao morador (só em production);
  - índices de correção: a última competência de cada um e a situação (ok, atrasado com mais de 2
    meses, ou nunca carregado);
  - fila de avisos: pendentes e com falha, só dos condomínios do escopo.
- **O que não mostra:** endereço de e-mail, host, usuário, senha ou qualquer valor de variável de
  ambiente. A página também não liga, desliga nem testa conexão.

## Consequências

- A rota e o OpenAPI entram no alinhamento (docs/handoff-0091-shared.md).
- Configurar banco real ou mudar o modo de e-mail continua fora do ERP, no cofre e no ambiente, com
  aprovação.
