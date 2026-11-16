# 0318 — Extrato e saldo buscados no Inter pela API, com a mesma prévia do OFX

- Status: Aceito (só sandbox do Inter)
- Data: 16/11/2026
- Pedido: Djalma, 16/11/2026, depois da lista do que falta do Inter ("vamos seguir com essa parte [...] vamos
  finalizar esse e aí a gente vai começar a integração com a Cora"). Item escolhido: extrato e saldo automáticos.
- Relacionadas: ADR 0058 (importação do extrato OFX, AP-140), 0156 (Inter), 0158 (credencial pela tela)

## Contexto

A conciliação dependia de baixar o OFX no Internet Banking e enviar à mão. O Inter entrega o extrato e o saldo
pela API Banking v2, com a mesma credencial e o mesmo certificado da cobrança.

## Decisão

1. Interface `BankStatementSource` (`statement(from, to)`, `balance(on)`), só leitura. O `InterBankAdapter`
   implementa com `GET /banking/v2/extrato/completo` (paginado, 100 por página, teto de 50 páginas) e
   `GET /banking/v2/saldo` (campo `disponivel`). Contrato INFERIDO da documentação pública; conferir no sandbox.
2. O escopo `extrato.read` vai num **token separado** do da cobrança: aplicação do Inter sem a permissão de
   extrato continua emitindo boletos, e o erro diz o que marcar na aplicação.
3. FITID = `inter-` + `idTransacao` (estável). Sem `idTransacao`, o FITID vem do conteúdo da linha e da ordem.
4. `BankStatementFetch` monta, com o que o banco devolve, um OFX igual ao enviado à mão (com o saldo como
   `LEDGERBAL` no fim do período) e chama `Imports::previewFetchedStatement`: mesma prévia, mesmo arquivo
   guardado, mesma aplicação, mesma conciliação e conferência de saldo do ADR 0058. Linhas já importadas
   aparecem como "Já importada"; o carimbo de hora no cabeçalho faz cada busca virar uma prévia nova.
5. API `POST /api/v1/imports/bank-statement {condominium_id, bank_account_id, from, to}`: mesmas permissões do
   OFX (erp.imports.manage + erp.entries.manage), período de até 90 dias e até hoje, fora do escopo = 404.
6. Tela Importações > Extrato bancário: cartão "Buscar extrato do banco" só para contas ativas com adaptador
   Inter e credencial no cofre.

## Consequências

- Nada é gravado antes de aplicar e nada movimenta dinheiro.
- Linhas do OFX manual e da API têm FITIDs diferentes: misturar os dois no mesmo período duplica linhas
  (a conciliação não acha dois lançamentos para o mesmo valor e a segunda fica pendente). A tela orienta usar um
  caminho por conta.
- Próximo passo possível: buscar o extrato sozinho todo dia (precisa de um usuário de sistema para a auditoria).
