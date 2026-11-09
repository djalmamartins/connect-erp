# 0108 — Fila de avisos: posse com prazo para quem está enviando

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Revisão da fila de avisos (ADR 0055)

## Contexto

Na ADR 0055, `NotificationDispatcher::dispatch` marcava o aviso como `processing` e só tirava dessa
situação ao terminar. Se o processo caísse no meio do envio (queda do PHP, do container ou do banco),
o aviso ficava em `processing` para sempre: a fila só pega `pending`, e o reenvio manual só aceita
`failed` ou `cancelled`. O aviso sumia sem falha visível.

`retry` também conferia a situação e depois atualizava sem condição.

## Decisão

- Ao pegar um aviso, `available_at` passa a ser o fim da posse (`LEASE_MINUTES` = 15 minutos).
- A fila pega `pending` vencido e também `processing` com a posse vencida. A tomada confere a mesma
  condição no `UPDATE`, e só um processo vence.
- Um aviso retomado que já passou de `MAX_ATTEMPTS` vai para `failed` sem novo envio. Daí, só o
  reenvio manual.
- Na retomada, quem já tem entrega registrada não recebe de novo (trilha por destinatário, ADR 0055).
- `retry` só atualiza se o aviso ainda estiver em `failed` ou `cancelled`. Se mudou no meio, a API recusa.

## Consequências

- Não há migration: usa colunas e índice existentes (`status`, `available_at`).
- Um envio que demore mais de 15 minutos pode ser retomado por outro processo. A trilha evita repetir
  quem já recebeu, mas um e-mail enfileirado sem a trilha gravada ainda pode sair duas vezes.
- O teste de integração (`testStuckProcessingIsReclaimedAfterLease`) roda só no Mac.
