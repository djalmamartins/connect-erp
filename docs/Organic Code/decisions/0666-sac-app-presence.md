# 0666 — "Você está online no balão do site" no app do SAC

- Status: Aceito
- Data: 27/11/2026
- Pedido: prévia aprovada pelo Djalma em 27/11 (seção 5, Fila) e ciclos de melhoria a cada 30 minutos (26/11 22:57).
  Continua as ADRs 0631 (balão do SAC), 0660, 0661 e 0664.

## Decisão

- Reaproveita a presença que o balão já usa (`Source\Services\Support\SacDesk`): `sac_seen_at` (ativo nos últimos
  5 minutos) e `sac_public` (a própria pessoa autoriza aparecer). Nada de tabela nova.
- **Ativo pelo celular:** `GET /api/v1/sac/summary` (a fila abre e atualiza por ela) e `/sac/presence` chamam
  `touch()`, como as telas do ERP e do Studio. Só conta quem tem `support.manage`.
- **`GET /api/v1/sac/presence`** devolve `public`, `open` (horário do balão), `hours`, `shown` (aparece agora, pelas
  mesmas regras do balão: autorizado, ativo, nível de equipe) e `online` (primeiros nomes mostrados).
  **`POST`** `{public: bool}` liga ou desliga, como a chave do Meu Dia no ERP, com auditoria.
- **App:** cartão no topo da Fila com a chave; verde quando aparece, cinza quando não autorizou ou fora do horário.
  Para quem só acompanha (sem `support.manage`), o cartão não aparece.

## Testes

- `SacDeskIntegrationTest::testPresenceOnTheSiteBalloon` (fila marca ativo, liga e desliga, valor inválido = 422,
  quem só acompanha = 403, aparece só no horário).
- `apps/sac/__tests__/tickets.test.ts`: textos da chave.
