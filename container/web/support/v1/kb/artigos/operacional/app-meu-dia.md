---
codigo:
titulo: App Meu Dia no celular (iPhone e Android)
resumo: A sua agenda de trabalho no celular, com lembrete antes de cada compromisso, resumo das 7h, as tarefas atrasadas em vermelho e tarefa marcada por outra pessoa da equipe.
categoria: operacional
rota: /meu-dia
menu:
icone: phone-portrait-outline
situacao: guia
relacionados: meu-dia, chamados
palavras: app, aplicativo, celular, iphone, android, meu dia, lembrete, notificação, push, tarefa atrasada, atrasada, agenda no celular, marcar tarefa para outra pessoa
manual: sim
rascunho: nao
---

O app **Meu Dia** é a mesma agenda da tela [[Meu Dia]] do ERP, no celular. Ele mostra o que você tem para fazer, avisa antes de cada compromisso e deixa em vermelho o que ficou para trás.

## Como entrar

1. Abra o app **Meu Dia Connect** no celular.
2. Entre com o **mesmo e-mail e senha do ERP**. Se a sua conta usa verificação em duas etapas, digite o código do aplicativo autenticador.
3. Na primeira vez, o celular pergunta se pode mostrar notificações. Toque em **Permitir**: é assim que chegam os lembretes.

Esqueceu a senha? Troque pela tela de login do ERP no computador. Só entra quem tem acesso ao ERP.

## As quatro abas

- **Hoje:** a faixa dos dias (toque num dia para ver), os números do dia, a agenda por horário, os prazos de chamados, as vistorias, os chamados **Para agendar** e os que aguardam o cliente.
- **Semana:** de segunda a domingo, com os compromissos de cada dia. As setas mudam a semana; toque num dia para abrir ele em Hoje.
- **Nova tarefa:** marca uma tarefa ou compromisso na agenda.
- **Conta:** quem está conectado, de quem é o dia mostrado, os lembretes e o botão {{Sair}}.

## Tarefas atrasadas ficam em vermelho

O que ficou para trás (um compromisso de ontem que não foi concluído, um chamado com o prazo vencido, uma vistoria vencida) aparece num quadro **vermelho** no alto da aba **Hoje**, com há quantos dias está atrasado. Ele continua aparecendo todos os dias, até ser fechado. Na faixa dos dias, o dia em que a tarefa ficou para trás também fica vermelho. O número no ícone do app é a quantidade de tarefas atrasadas.

Para fechar: toque na tarefa e escolha **Concluir**. Chamado fecha no SAC: toque nele e escolha **Abrir no app do SAC** para responder, ou **Marcar horário para atender**. Quando fecha, sai do vermelho e tudo volta ao normal.

## Concluir, cancelar ou reabrir

Toque no compromisso na aba **Hoje** e escolha **Concluir** ou **Cancelar**. Se concluiu sem querer, toque de novo e escolha **Reabrir**.

## Marcar uma tarefa

1. Abra a aba **Nova tarefa**.
2. Escreva **O que fazer**, escolha o **Tipo**, o **Dia** (ou os atalhos Hoje, Amanhã, Daqui a 1 semana) e a hora em que **Começa**.
3. Se tiver a permissão para a equipe, escolha **Para quem**.
4. Toque em {{Salvar tarefa}}.

Num chamado de **Para agendar**, toque nele para marcar o horário de atender: a tarefa já vem com o assunto do chamado.

## Marcar uma tarefa para outra pessoa

Quem tem a permissão **Ver o Meu Dia da equipe** escolhe em **Para quem** qualquer pessoa da equipe. A tarefa entra no Meu Dia dela (no ERP e no app) e ela recebe um aviso no celular: "Fulano marcou uma tarefa para você", com o que é, o dia e a hora. Na aba **Conta**, em **Ver o dia de**, dá para acompanhar o dia de outra pessoa nas abas Hoje e Semana.

## Lembretes

- **15 minutos antes** de cada compromisso marcado na semana.
- **Às 7h**, um resumo do dia: quantos compromissos, quantos chamados para agendar e quantas tarefas estão atrasadas.
- **Na hora**, quando alguém marca uma tarefa para você.

Os lembretes podem ser desligados em **Conta > Lembretes neste celular**. Ao sair do app, o celular para de receber os avisos da sua conta.

## O que acontece por trás

- O app usa a mesma API do ERP. As regras são as mesmas da tela: cada pessoa vê o próprio dia, e só quem tem a permissão da equipe vê o dia de outra pessoa ou marca tarefa para ela. Toda marcação, conclusão e cancelamento entra no histórico.
- O login fica guardado no cofre do celular (Keychain no iPhone, Keystore no Android). A senha nunca é guardada. A sessão termina sozinha depois de 30 dias sem uso, e o **Sair de todos os aparelhos** do ERP também desconecta o app.
- Os lembretes de 15 minutos ficam no próprio celular e funcionam mesmo sem internet; são refeitos toda vez que a aba Hoje carrega. O aviso de tarefa nova e o resumo das 7h vêm do servidor.
- O texto dos avisos traz só o título da tarefa, o condomínio e o horário: nada de dado de morador nem valor.

>> Veja também: [Meu Dia: a sua agenda e as suas tarefas](artigo:meu-dia) e [Abrir e acompanhar chamados](artigo:chamados).
