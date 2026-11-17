# ADR 0353 — Connect Talk no relatório e na anonimização do titular

- Status: Aceito
- Data: 17/11/2026
- Relacionados: ADR 0328 (relatório do titular), ADR 0330 (anonimização), ADR 0350 (registro LGPD), ADR 0351, ADR 0324 (Talk no ERP)

## Contexto

O registro das operações (ADR 0350, §4) deixou o Connect Talk fora do relatório do titular: lá a pessoa é achada pelo
telefone, não pelo CPF ou e-mail. O Talk guarda nome, telefone, uma cópia do cadastro do ERP, mensagens trocadas pelo
WhatsApp e envios de campanha. O Talk atende todos os condomínios juntos: as tabelas não têm `condominium_id`.

## Decisão

- **Busca:** os telefones dos cadastros de morador e do conselho que a busca já achou (CPF/CNPJ ou e-mail) viram as
  variações que o próprio Talk usa (`Phone::candidates`: com e sem DDI, com e sem o 9º dígito). Com elas o relatório traz
  `talk_contacts`, `talk_conversations`, `talk_messages` e `talk_campaign_recipients`. Sem anotações da equipe
  (`talk_contact_notes`) nem dados do canal.
- **Escopo:** como o Talk não é separado por condomínio, esses grupos só aparecem para quem tem acesso a todos os
  condomínios (`hasGlobalAccess`). Quem vê só alguns condomínios não chega a conversas que podem ser de outro prédio.
- **Anonimização (mesma regra):** o contato vira "Titular anonimizado #id", o telefone vira `anon-{id}` (a coluna é única
  e obrigatória), a cópia do cadastro e o vínculo com o ERP são apagados, as anotações da equipe sobre ele são apagadas, e
  os envios de campanha e da fila já enviados perdem o telefone. As mensagens ficam, como o texto do chamado: são o
  registro do atendimento. A prévia mostra quantas ficam em `kept.talk_messages`.

## Consequências

- Se a pessoa voltar a escrever no WhatsApp depois de anonimizada, o Talk cria um contato novo. O bloqueio e o pedido para
  não receber campanha do contato antigo se perdem junto com o telefone (a campanha sai do cadastro do ERP, que também
  ficou sem telefone).
- Telefone digitado só no Talk, sem cadastro no ERP, não é achado por esta busca.

## Testes

- `PersonalDataExportTest::testFindsTalkByPhoneOnlyWithGlobalAccess` e
  `PersonalDataAnonymizerTest::testAnonymizesTalkOnlyWithGlobalAccess` (SQLite): telefone com máscara e sem DDI, outro
  contato não muda, anotação não sai no relatório, nada do Talk sem acesso a todos.
- `PersonalDataTalkIntegrationTest` (MariaDB): o mesmo nas tabelas reais.
