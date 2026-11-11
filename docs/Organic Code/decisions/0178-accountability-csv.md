# 0178 — CSV da prestação de contas mensal

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0087 (limite de exportações), 0166 (prestação de contas, AP-101), 0167 (CSV do Balanço),
  0177 (fundos na prestação de contas)

## Contexto

A prestação de contas mensal (AP-101) é o documento que a administradora manda ao síndico e aos moradores, mas só
saía em JSON. Os outros relatórios financeiros (Balanço, Razão, inadimplência) já baixam em CSV, com limite de
exportações e auditoria.

## Decisão

`GET /api/v1/reports/accountability?condominium_id=&month=AAAA-MM&format=csv` baixa o mesmo pacote em CSV. O
endpoint segue o padrão do Balanço:

- as mesmas permissões (`erp.access`, `erp.entries.view` e `erp.chart.view`) e o mesmo escopo (fora = 404);
- até 30 exportações por hora (`ReportExportLimit`); acima disso, 429;
- auditoria `export` em `erp_reports`, com o relatório, o condomínio, o mês e o tamanho;
- `Cache-Control: no-store`, `Content-Disposition: attachment` e `X-Content-Type-Options: nosniff`.

### O arquivo

- É uma tabela só, montada por `Accountability::csvTable()`, uma regra pura.
- As colunas são Seção, Código, Item, Saldo anterior, Entradas, Saídas e Valor ou saldo final.
- As seções, na ordem:
  - Resumo;
  - Fechamento;
  - DRE;
  - Bancos;
  - Fundos, com os movimentos;
  - Inadimplência, por unidade, sem nome de morador;
  - Avisos.
- Pacote truncado ganha um aviso no fim.
- Todo texto que vem do cadastro passa por `CollectionReports::csvCell`, que neutraliza fórmula. Valores em reais,
  com vírgula. BOM UTF-8, separador `;`, linhas CRLF.

## Consequências

- **Testes.** `AccountabilityDeliveriesOpeningsTest` confere:
  - o cabeçalho, as seções e os valores do pacote de outubro (resumo, banco e inadimplência);
  - que texto com `=`, `+`, `-` e `@` em todas as seções sai neutralizado;
  - o aviso de pacote truncado;
  - que o controller tem limite, auditoria, permissão e 429.
- **Sem migration, rota ou tela.** A rota já existe; só ganhou o `format=csv`. A tela pode pôr um botão "Baixar
  CSV" quando a frente de layout quiser.
