# 0650 — Nome exibido do ERP: "ERP Connect Condomínios"

- Status: Aceito
- Data: 26/11/2026
- Pedido: Djalma, 26/11/2026 06:50: "Troca o nome do [ERP] ... Coloca [ERP] Connect Condomínios. Eu acho que completa
  o nome todo do administrador, fica muito mais legal ... muito mais bem apresentado."
- Relacionadas: ADR 0315 (2FA com o nome Connect Condomínios, a troca parcial anterior), 0627 (alternador de ambientes
  único), 0141 (Central de Ajuda)
- Número: 0649 está em uso no branch da CondoConta.

## Contexto

O ERP aparecia como "Connect ERP" (título da aba, login, menu lateral, alternador, rodapé dos relatórios e PDFs,
e-mails de teste e do vigia, Central de Ajuda). Só o aplicativo autenticador do 2FA já mostrava "Connect Condomínios"
(ADR 0315). Não houve outro pedido de troca registrado no repositório.

## Decisão

Todo nome **exibido** do ERP passa a ser **ERP Connect Condomínios**:

- título da aba (`Entrar · ERP Connect Condomínios`, `<tela> · ERP Connect Condomínios`);
- login e 2FA do ERP: linha de cima "ERP CONNECT CONDOMÍNIOS";
- menu lateral: botão do alternador com a linha pequena "ERP" e o nome "Connect Condomínios" (lido junto,
  "ERP Connect Condomínios", sem repetir "ERP"); link do logo com o nome completo para leitor de tela;
- painel "Ambientes Connect" (ERP, Studio e SAC): item "ERP Connect Condomínios";
- rodapé dos relatórios A4, do livro caixa e do relatório de vistoria ("Gerado ... pelo ERP Connect Condomínios");
- e-mail de teste do Studio e de `tools/hosting/email-teste.php`, alertas do vigia da hospedagem;
- Central de Ajuda: botão do topo, chamada da página inicial, artigos de primeiros passos e dos bancos, e o resumo
  gerado pelo `help.php scaffold`.

Não mudam identificadores internos (rotas, permissões `erp.*`, pastas `container/erp/connect`, namespaces, nomes de
tabela, comentários de código e documentação técnica) nem o título técnico do contrato OpenAPI. O app do morador e o
site já usam "Connect Condomínios" e não falam em ERP.

## Consequências

- Os prints marcados da Central de Ajuda que mostram o menu lateral ainda trazem o nome antigo até a próxima geração
  por `tools/help/prints.sh`.
- Testes: `EnvironmentSwitcherTest` e `tests/erp/render-smoke.php` conferem o nome novo.
