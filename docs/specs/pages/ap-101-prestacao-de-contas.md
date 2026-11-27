# AP-101 — Prestação de Contas

- **Domínio:** relatorios
- **Rota de referência (APControle; NÃO rota obrigatória do Connect ERP):** `/relatorios/relatorio`
- **Situação da rota na auditoria:** CONFIRMADA
- **Fonte:** `docs/reference/AUDITORIA_COMPLETA_APCONTROLE.pdf`, página 13
- **Natureza desta ficha:** scaffold gerado automaticamente; detalhes ainda NÃO confirmados

## Objetivo funcional
PENDENTE: detalhar com base na auditoria e decisões próprias do Connect ERP.

## Campos, controles, filtros, colunas e links
PENDENTE: levantar evidências específicas; não inferir a partir da quantidade de controles.

## Modelo de dados e dependências
PROPOSTO: mapear entidades canônicas e relacionamentos após inspeção do Organic Code.

## Permissões e segurança
PROPOSTO: autorização server-side por ação e condomínio (administradora única — ADR-002); testes negativos obrigatórios.

## UI e estados
PROPOSTO: aplicar componentes do design system, incluindo vazio, erro, carregamento e acesso negado.

## Critérios de aceite
- [ ] Campos e regras confirmados ou decisões documentadas
- [ ] Fluxo implementado ponta a ponta, com dados sintéticos
- [ ] Isolamento, validações, auditoria e testes revisados
- [ ] Navegação e acessibilidade verificadas

## Pendências / perguntas
- Quais regras não foram exercitadas pela auditoria somente leitura?
- Quais links e estados condicionais exigem especificação adicional?
- Qual dependência deve ser entregue antes desta página?

## Revisão de 15/11 (ADR 0314, itens 19 e 21)
- CONFIRMADO (Djalma, 15/11): a prestação de contas segue o PDF de outubro:
  - lista dos meses;
  - página de visualização;
  - PDF com capa, termo de abertura, parecer do síndico e conselho, demonstrativo, resumo das contas, relatório de
    lançamentos e comprovantes;
  - "Ocultar unidade".
- Implementado:
  - tabela dos 12 últimos meses (Visualizar e PDF);
  - `GET /prestacao-de-contas/prestacao-de-contas/documento` e `/pdf` (`AccountabilityDocument`);
  - caixa "Ocultar unidade".
- FEITO (ADR 0323):
  - colunas "Sobras de rateio" e "Fundo de reserva" por despesa;
  - comprovantes em PDF juntados no mesmo arquivo.
- FEITO (ADR 0652): PDF em A4 retrato, com totais em verde e linhas listradas.
