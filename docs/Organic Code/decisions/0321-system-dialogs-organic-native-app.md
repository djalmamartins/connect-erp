# 0321 — Modal do sistema também no Organic e "dá para desfazer" no app de celular (etapa 3)

- Status: Aceito
- Data: 16/11/2026
- Pedido: Djalma, 16/11/2026 ("isso pode implementar"), sobre as duas sobras da ADR 0320
- Relacionadas: ADR 0316 (ERP), 0320 (resto do sistema), 0303 (app nativo do morador)

## Decisão

1. **Distribuição do Organic V2** (`container/shared/assets/organic/organic*.js`, gerada fora deste repositório):
   as duas perguntas (aplicar template e apagar rascunho/revisão) passam a usar `ConnectDialog.confirm` com o
   que vai acontecer e se dá para desfazer. Só se a página não tiver o `connect-dialog.js` (uso fora do Connect)
   cai no confirm do navegador. O ajuste foi feito nas quatro saídas (`.js`, `.min.js`, `.global.js`,
   `.global.min.js`) e precisa ir para o código-fonte do Organic; até lá, `SystemDialogsEverywhereTest` falha se
   uma sincronização (`service/commands/sync-organic-v2.php`) apagar o ajuste.
2. **App React Native** (`apps/morador`): já usava a janela própria (`src/ui/Dialog.tsx`, 14/11), não o Alert do
   sistema. Agora a janela também mostra a faixa "Dá para desfazer." / "Não dá para desfazer." (`undo`,
   `undoText`), usada em cancelar visita e reserva, bloquear inquilino, sair do app e trocar o desbloqueio por
   e-mail e senha. O recado rápido (toast) é desenhado dentro da janela aberta, para ficar por cima dela
   (`zIndex` 99999).

## Consequências

- Nenhum ponto do Connect abre mais a janela do navegador ou do sistema operacional para perguntar algo.
- Sem mudança de banco, rota, API ou permissão.
