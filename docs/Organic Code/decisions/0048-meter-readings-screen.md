# 0048 — Tela de leituras do mês (AP-042)

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

O ADR 0045 trouxe relógios, tarifas em faixas e leituras com consumo, valor e cobrança, pela API
`/api/v1/meter-readings`. Relógios (AP-041) e tarifas (AP-043) já têm tela pelo motor de cadastros; a
página de leituras (AP-042) mostrava só a demonstração.

## Decisão

- `Panel::meterReadingData` (com `erp.meter_readings.view`) monta a folha da competência
  (`?competencia=AAAA-MM`, padrão o mês atual) e do tipo (`?tipo=agua|gas|energia`): uma linha por
  relógio ativo do condomínio da barra superior, com a leitura anterior e a do mês
  (`Panel::readingSheet`, regra pura). Sem permissão ou sem banco, segue a demonstração.
- **Lançar em lote** (com `erp.meter_readings.manage`): um campo por relógio, com a data da leitura
  para todos; mostra o consumo enquanto digita e marca leitura inválida ou menor que a anterior antes
  de enviar. Salvar manda só as alteradas, uma a uma, e marca na linha a que deu erro (as outras ficam
  salvas). Leitura cobrada não tem campo.
- **Indicadores**: relógios lidos, consumo, valor e cobradas; aviso das leituras sem tarifa vigente
  (valor zero, fora da cobrança), com link para Tarifas.
- **Faturar**: só com um condomínio escolhido na barra superior (a cobrança é por condomínio). Pede a
  conta de receita analítica do plano (padrão ou do condomínio) e o vencimento e gera as contas a
  receber das leituras com valor e não cobradas (tudo ou nada, pelo serviço).

## Consequências e lacunas

- A leitura anterior vem das 500 leituras mais recentes do tipo no escopo; com mais histórico, a tela
  cai para a leitura inicial do relógio, mas o serviço confere a anterior de verdade ao gravar.
- Salvar em lote faz uma chamada por relógio (sem endpoint de lote).
- Testado sem MariaDB: render-smoke com dados simulados, teste unitário e chamadas conferidas com
  mocks no navegador. O modal de faturar não foi exercitado no navegador (depende do condomínio em
  contexto na sessão).
