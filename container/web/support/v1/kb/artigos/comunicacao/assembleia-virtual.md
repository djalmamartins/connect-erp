---
codigo: AP-018
titulo: Assembleia Virtual
resumo: Criar a assembleia com a pauta, convocar pelo edital, abrir, registrar a presença por unidade, encerrar com a ata ou cancelar.
categoria: comunicacao
rota: /comunicacao/assembleia-virtual
menu: Comunicação > Canais > Assembleia Virtual
icone: people-outline
situacao: funciona
relacionados: votacao, listagem-de-presencas, parecer
palavras: assembleia virtual, assembleia, AGO, AGE, edital, pauta, presença, procuração, ata, AP-018
rascunho: nao
---

## Para que serve

Aqui a assembleia nasce e anda até a ata: **rascunho** com a pauta, **convocada** com o edital publicado, **em andamento** com a presença das unidades e **encerrada** com a ata gerada. Ela pode ser presencial, virtual ou híbrida. A votação dos itens fica em [Votação](artigo:votacao).

## Onde fica

No menu lateral, abra [[Comunicação > Canais > Assembleia Virtual]].

```tela assembleia-virtual
rota: /comunicacao/assembleia-virtual
condominio: residencial-jardim-das-acacias
legenda: Tela Assembleia Virtual
1: menu | Assembleia Virtual no menu lateral
2: main [data-erp-assemblies] | Nova assembleia e a lista das assembleias
```

## Criar a assembleia

>! A assembleia é de **um condomínio**. Escolha o condomínio na barra superior; com "Todos os condomínios", a tela só mostra a lista.

1. Em **Nova assembleia**, preencha o título, o tipo (ordinária ou extraordinária), o formato e o local ou o link da sala.
2. Informe a **primeira convocação** e, se quiser, a **segunda** (pelo menos 30 minutos depois).
3. Monte a **pauta**: cada item tem o assunto, a **base do voto** (fração ideal ou uma unidade, um voto) e o **quórum** (maioria simples dos votos, maioria absoluta do condomínio, dois terços ou unanimidade). Use {{Adicionar item}} para até 20 itens.
4. Clique em {{Criar rascunho}}. A tela abre o detalhe da assembleia.

## Do edital à ata

1. **Convocar:** {{Convocar}} publica o edital pela fila de avisos, uma mensagem por unidade. O edital sai com pelo menos 8 dias de antecedência da primeira convocação. Fora de produção, os e-mails vão só para o log ou para a caixa de teste.
2. **Abrir:** a partir do horário da primeira convocação, {{Abrir a assembleia}}.
3. **Presença:** em **Registrar presença**, escolha a unidade e quem está presente. Por procuração, marque {{Por procuração}} e escolha o arquivo da procuração, enviado antes em [[Documentos > Disco Virtual]]. Unidade com conta vencida em aberto fica presente, mas **não vota** (Código Civil, art. 1.335, III).
4. **Votar:** vote cada item em [Votação](artigo:votacao).
5. **Encerrar:** com todos os itens apurados, {{Encerrar e gerar a ata}}. A ata fica guardada com um código de conferência (SHA-256) e não muda mais.

Rascunho e assembleia convocada podem ser canceladas em {{Cancelar a assembleia}}, com o motivo. O edital que ainda não saiu é cancelado junto.

## Quem pode usar

Ver exige a permissão **erp.assemblies.view**; criar, abrir, registrar presença e encerrar, **erp.assemblies.manage**. Convocar também exige **erp.announcements.manage**, porque publica um comunicado. Cada pessoa só vê os condomínios vinculados à própria conta, e toda mudança fica na auditoria.
