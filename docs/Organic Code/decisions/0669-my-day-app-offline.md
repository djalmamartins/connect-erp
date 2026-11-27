# 0669 — App Meu Dia: o dia sem internet

- Status: Aceito
- Data: 27/11/2026
- Pedido: Djalma, 27/11/2026: "continuar desenvolvendo e melhorando o sistema a cada 30 min" (ciclo 6 do app Meu Dia).
  O Meu Dia é a agenda para consultar no dia a dia, e na rua (portaria, garagem, subsolo) a internet cai.
- Depende de: ADR 0659 (app Meu Dia).

## Decisão

- Toda resposta do `GET /my-day` aberta na aba Hoje é guardada em `meu-dia-offline.json`, na pasta de documentos do
  próprio app (`expo-file-system`), por pessoa vista e por dia (`eu:2026-11-27`, `12:hoje`), só os 7 mais recentes.
- Quando o pedido falha por falta de conexão ou tempo esgotado (`network`/`timeout`), Hoje mostra a cópia daquele dia com
  o aviso amarelo "Sem conexão. Mostrando os dados de hoje às 14:32. Puxe para atualizar." Outros erros (401, 403, 500)
  continuam aparecendo como erro: a cópia não esconde sessão vencida nem falta de permissão.
- A cópia é só da pessoa conectada (outra pessoa que entrar no aparelho começa do zero) e é apagada ao sair ou quando a
  sessão é recusada (401). LGPD: o dia traz título de compromisso, condomínio e assunto de chamado, sem dado financeiro;
  fica dentro do app, que o sistema protege, e some ao sair.
- Sem internet, concluir, adiar e marcar continuam pedindo a conexão (mostram o erro de rede). Não há fila de ações
  offline nesta etapa.
- Nada muda no servidor. Dependência explícita: `expo-file-system` (mesma versão do morador e do SAC).

## Testes

- `apps/meu-dia/__tests__/offline.test.ts`: limite de 7 dias, separação por pessoa conectada e o texto da hora.
