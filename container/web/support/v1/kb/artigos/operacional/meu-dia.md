---
codigo: CN-010
titulo: Meu Dia: a sua agenda e as suas tarefas
resumo: Veja num lugar só os compromissos do dia e da semana, os chamados que estão com você, as vistorias que vencem e os documentos para vencer.
categoria: operacional
rota: /meu-dia
menu: Meu Dia
icone: time-outline
situacao: funciona
relacionados: vistorias, chamados
palavras: meu dia, agenda, calendário, tarefas, compromisso, agendar, semana, hoje, CN-010
rascunho: nao
---

Veja num lugar só os compromissos do dia e da semana, os chamados que estão com você, as vistorias que vencem e os documentos para vencer.

## Onde fica

No menu lateral, clique em [[Meu Dia]], logo abaixo do Dashboard. A tela abre sempre no dia de hoje.

## O que a tela mostra

- **Cartões do topo:** quantos compromissos você tem no dia (e quanto tempo somam), quantos chamados esperam uma data, quantos aguardam o cliente, o que está atrasado e quantas vistorias caem na semana.
- **Agenda da semana:** os sete dias em círculos. O dia de hoje tem um anel dourado e o número em cada dia diz quantos itens ele tem. Clique num dia para abri-lo.
- **Agenda:** na aba **Dia**, os compromissos por horário e, no alto, o que vale para o dia todo (vistoria marcada, prazo de chamado). Na aba **Semana**, a lista de cada dia.
- **Para agendar:** os chamados atribuídos a você que ainda não têm horário, com a etiqueta Urgente, Importante ou Rotina e o prazo.
- **Aguardando cliente**, **Próximas vistorias** e **Documentos e vencimentos**.
- **Painel da visita:** ao escolher uma vistoria, mostra o que acompanhar no condomínio (chamados abertos, documentos que vencem, pendências da última vistoria e a última visita) e o botão {{Iniciar visita}}.

## Como marcar um compromisso

1. Clique em {{Novo compromisso}}.
2. Preencha **O que é**, o **Dia**, o **Início** e, se quiser, o **Fim**.
3. Escolha o **Tipo** e, se for num condomínio, o **Condomínio**.
4. Clique em {{Marcar}}.

## Como agendar um chamado

1. Em **Para agendar**, clique em {{Agendar}} no chamado.
2. O formulário já vem com o assunto e o condomínio do chamado. Escolha o dia e o horário.
3. Clique em {{Marcar}}. O chamado sai da lista e entra na agenda.

Quando terminar, use o botão de concluir (✓) no compromisso. Para desmarcar, use o de cancelar (×).

## Vistoria que vira chamado

Em [[Agenda > Atendimento > Vistorias]], no {{Configurar}}, cada plano de vistoria tem um **Responsável**. Quando a vistoria está para vencer, o sistema abre um chamado de vistoria para essa pessoa, que aparece no Meu Dia dela. Ao concluir a vistoria, o chamado é resolvido sozinho, e o que teve problema vira um chamado de pendências do condomínio.

## Aparecer no balão do SAC

Quem atende chamados vê, no fim da tela, o quadro **Balão do SAC no site**. Clique em {{Autorizar aparecer}} para o site mostrar seu primeiro nome e sua foto como **online** enquanto você está no sistema, em dia útil e no horário de atendimento. Para sair, clique em {{Não aparecer mais}}. Sem autorizar, você atende normalmente, só não aparece no site. E-mail, sobrenome e telefone nunca aparecem.

## O que acontece por trás

Quando você usa esta tela, o sistema faz o seguinte:

- A tela só junta o que já existe no sistema: a agenda, os chamados atribuídos a você, os planos de vistoria em que você é o responsável e os itens do checklist de chegada sob sua responsabilidade. Nada muda quando você só abre a tela.
- "Agendar" um chamado cria um compromisso ligado a ele; o chamado sai da lista "Para agendar" enquanto o compromisso estiver marcado.
- Quando uma vistoria vence em breve, a rotina das 7h abre um chamado de vistoria para o responsável do plano. Ao concluir a vistoria, esse chamado é resolvido sozinho e os itens com problema viram um chamado de pendências.
- No balão do SAC, conta como online quem atende chamados, autorizou aparecer e abriu uma tela do ERP ou do SAC nos últimos 5 minutos. A autorização e a retirada entram no histórico.
- Cada pessoa vê o próprio dia. Ver o dia de outra pessoa ou marcar compromisso para ela exige a permissão "Ver o Meu Dia da equipe". Toda marcação, conclusão e cancelamento entra no histórico.

Um exemplo: a gerente abre o chamado "Portão quebrado" e atribui a você. Ele aparece em **Para agendar** com a etiqueta Urgente. Clique em {{Agendar}}, escolha o horário, e ele entra na sua agenda do dia.

## Quem pode usar

Todo mundo da equipe com acesso ao ERP vê o próprio dia. Quem tem a permissão **Ver o Meu Dia da equipe** escolhe, no alto da tela, de quem quer ver o dia e pode marcar compromissos para outras pessoas. O administrador libera em [[Studio > Usuários]].

>> Veja também: [Vistorias nos condomínios](artigo:vistorias) e [Abrir e acompanhar chamados](artigo:chamados).
