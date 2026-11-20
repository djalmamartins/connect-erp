# 0386 — 2ª via do boleto para o Connect Talk

- Status: Aceito
- Data: 18/11/2026
- Contexto: issue #400 §6 (rota que faltava da API do Talk, ADR 0308). Desde o ADR 0359 o Talk roda no Raspberry Pi do
  escritório e fala com o ERP só por esta API, com o token `ctk_`.

## Decisão
`GET /api/v1/talk/charges?resident_id=&phone=` (`TalkCharges::open`, OpenAPI tag Talk), com as regras da issue:

- exige o morador **e** o telefone dele (mesmos 8 últimos dígitos do cadastro, aceita com ou sem 55 e nono dígito);
  morador de outro condomínio, inativo, fora do período, sem unidade, de outro vínculo ou com telefone diferente = **404**
  com a mesma mensagem, sem dizer o motivo;
- só **proprietário** ou **responsável financeiro** (`owner`, `financial`). Inquilino e morador ficam de fora: a issue
  deixou PENDENTE e a escolha mais segura é não mostrar boleto a quem não paga. **[Djalma]** pode liberar o inquilino;
- só `erp_bank_charges.status = registered` de conta a receber da unidade em aberto (não paga, ativa), vencida há no
  máximo 60 dias; no máximo 3, pelo vencimento;
- `portal_url` aponta para a página do boleto no app do morador (`/boletos/{lançamento}`, exige login). Sem link
  assinado para o PDF: fica para quando a assistente do Talk sair da proposta;
- cada consulta vai para a auditoria (`system_audit_logs`, ação `read`, `second_copy`); o telefone sai mascarado
  (a própria auditoria esconde o campo `phone`);
- limite de 60 consultas por minuto por IP (429), além do limite de falhas do token.

## Consequências
- O Talk pode mandar a linha digitável e o Pix copia e cola pelo WhatsApp a quem prova ser o titular do telefone do cadastro.
- Telefone trocado ou desatualizado no cadastro = o Talk passa para o Financeiro.
- Testes: `TalkIntegrationTest` (filtros, limite de 3, 404 sem motivo, inquilino fora, auditoria mascarada, rota com token).
