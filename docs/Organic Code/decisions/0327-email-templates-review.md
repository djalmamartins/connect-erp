# 0327 — Revisão do HTML dos e-mails e trava contra valor sem escape

- Status: Aceito
- Data: 16/11/2026
- Pedido: `docs/ROADMAP_V1.md` etapa 6 (pendente do ADR 0159: revisão do HTML dos templates); Djalma, 16/11/2026: "tome as melhores decisões"
- Relacionadas: ADR 0008 (container send), 0159 (revisão de segurança da API), 0326 (limite por IP)

## Revisão

- **Templates do `container/send`** (15 páginas e a moldura): os 92 `<?=` escapam o valor com `$this->e()`,
  `nl2br($this->e())` ou são número (`(int)`), a seção da moldura ou a escolha entre dois textos fixos. Links (`href`)
  também passam pelo escape e são montados no servidor.
- **E-mails montados em código** (`TicketService`, `Communication`, `ProposalMailer`, agenda do Studio, `AppLogger`):
  nome, assunto, texto do chamado e link passam por `htmlspecialchars`; `Communication::safeLink()` só aceita link do
  próprio sistema.
- **Assunto**: vai para o `Subject` do PHPMailer, que remove quebra de linha (sem injeção de cabeçalho).
- Nada a corrigir. Fica registrado que alguns e-mails internos ainda dizem "MovesOS" (nome da plataforma); trocar o
  nome é decisão de marca do Djalma, não de segurança.

## Decisão

`SendTemplatesEscapeTest` lê todos os templates do `container/send` e falha quando um `<?=` não está numa das formas
seguras acima. Um template novo com valor sem escape quebra o CI.

## Consequências

- Fecha o último pendente do ADR 0159 (o teste de carga dos limites virou o ADR 0326).
- O teste não cobre os e-mails montados em código; esses continuam na revisão de código.
