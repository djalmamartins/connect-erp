# ADR 0151 — Menu Condomínios abre a visão geral, guia de cadastro do condomínio novo e CNPJ automático

- Situação: aceita (10/11/2026)
- Contexto: pedido do Djalma em 10/11/2026; contexto global do condomínio (ADR 0030), cadastro AP-004
  (ADR 0011), checklist de chegada (ADR 0013), cadastro rápido de unidades (ADR 0140)

## Contexto

O menu Condomínios › Condomínios abria sempre a lista, mesmo com um condomínio escolhido na barra superior
(nesse caso, uma lista de uma linha só). A visão do condomínio (antiga "ficha") mostrava só nome, CNPJ e
situação. Ao salvar um condomínio novo, o sistema levava direto ao checklist de chegada, antes de existirem
unidades e pessoas. O Djalma pediu:

1. com um condomínio na barra superior, o menu abre direto a visão geral dele, com todas as informações;
   em "Todos os condomínios", a lista com um botão para selecionar o condomínio em todo o sistema;
2. depois de salvar o condomínio, seguir a ordem: unidades → proprietários de cada unidade → síndico e
   conselho → áreas vinculadas → checklist por último (os prazos continuam definidos no checklist);
3. no formulário, o CNPJ primeiro e, ao digitá-lo, buscar e preencher os dados do condomínio;
4. no síndico e conselho, e-mail e telefone obrigatórios (canal de comunicação).

## Decisão

- `Condominiums::index`: com `CondominiumContext::current()`, entrega a visão geral (mesma tela de
  `/condominios/{id}`); sem contexto, a lista. Cada linha ativa tem **Selecionar**, um link
  `erp_context_url('/condominios', $opção)`: a URL ganha o slug, o `CondominiumContext` grava a sessão e todas
  as telas passam a responder a esse condomínio. Volta-se à lista por "Todos os condomínios" (barra superior
  ou botão da visão geral). O aviso "Mostrando só..." saiu, porque esse caso não existe mais.
- Visão geral (`pages/condominiums/show`): dados completos de `CondominiumRegistry::find` (identificação,
  endereço e contato, estrutura, contrato e banco), o cartão **Cadastro do condomínio** com os passos e os
  cadastros ligados. Somente leitura; editar continua em `/condominios/{id}/editar`.
- `Source\Services\Erp\CondominiumSetup`: define os passos na ordem pedida, cada um apontando para a tela que
  já existe (editar, cadastro rápido de unidades, proprietários e inquilinos, síndico e conselho, áreas
  vinculáveis, checklist). Só conta registros (sem gravar): proprietários = unidades com pelo menos um
  vínculo "Proprietário" ativo, comparadas ao total; checklist = itens regulares ou não aplicáveis sobre o
  total. Áreas vinculadas aparecem como "Opcional" enquanto vazias. Um passo só aparece para quem tem a
  permissão `*.view` da tela dele; a autorização continua em cada tela.
- O guia fica na sessão (`CondominiumSetup::GUIDE_KEY` = id do condomínio). `store()` abre o guia e leva ao
  primeiro passo pendente com `?condominio={id}`, que troca a barra superior para o condomínio novo. Enquanto
  o condomínio do guia está na barra superior, a moldura (`default.php` + `components/layout/setup-guide`)
  mostra os passos no topo das telas de cada passo, com **Próximo: ...** e **Fechar o guia**; no checklist, o
  botão vira **Concluir cadastro**. `POST /condominios/{id}/guia` (retomar) e `/guia/encerrar` exigem CSRF e
  escopo. Salvar a edição dos dados durante o guia leva ao próximo passo pendente. Condomínio salvo como
  inativo não abre o guia (unidades e pessoas pedem condomínio ativo).
- O checklist continua nascendo junto com o condomínio (`OnboardingChecklist::start`, ADR 0013), com os
  prazos como hoje; só deixou de ser a primeira tela.
- Formulário: CNPJ primeiro, depois Nome e Razão social. Com 14 dígitos, o `erp.js` consulta a BrasilAPI
  (`/api/cnpj/v1/{cnpj}`, dados públicos da Receita) direto do navegador, como já faz com o ViaCEP: só o CNPJ
  sai, sem cookies nem referrer. Preenche apenas campos vazios (nome fantasia ou razão social, razão social,
  CEP, endereço, telefone, e-mail) e avisa se o CNPJ não está "ativa" na Receita. O servidor valida tudo de
  novo; o CNPJ continua opcional.
- Síndico e conselho (`Definitions` `board`): `email` e `phone` passam a `required`, no formulário, na API
  (`registries.openapi.yaml` regenerado) e na validação do motor.

## Consequências

- Registros antigos de síndico e conselho sem e-mail ou telefone só são salvos de novo quando completados.
- Sem acesso à internet no navegador, o CNPJ não preenche nada e a tela avisa; o cadastro segue manual.
- INFERIDO: "áreas vinculadas" é a página Áreas vinculáveis (AP-007), e "proprietários de cada unidade" conta
  unidades com vínculo Proprietário. O guia não bloqueia nada: dá para pular passos e voltar depois.
