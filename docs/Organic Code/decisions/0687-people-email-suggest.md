# 0687 — Sugestão de pessoa e e-mail ao digitar, em todo o sistema

- Status: Aceito
- Data: 27/11/2026
- Pedido: Djalma, 27/11/2026 17:50 (Brasília): "Em todas as partes do sistema, quando tiver de procurar uma pessoa
  ou um e-mail, quando começasse a digitar, devia buscar direto no banco de dados para facilitar e ficar mais rápido.
  E se a pessoa não tiver, digita tudo."

## Contexto

Cada tela pedia o nome ou o e-mail como texto livre (chamado, encomenda, assembleia, cobrança, vistoria, cadastro de
moradores) ou com um `<select>` com todas as contas do sistema (solicitante e responsável do chamado no Help Desk,
inclusive moradores de todos os condomínios). Já existiam duas buscas próprias de pessoas (ADR 0162: vínculo de pessoa
e troca de ocupante), com fluxo próprio, que continuam como estão.

## Decisão

1. **Um componente só**: `container/shared/assets/connect-fields/connect-suggest.js` (+ estilo `.cx-suggest` em
   `connect-fields.css`), carregado pelo `connect_fields_assets('js')` em todos os layouts, com o endereço da API no
   próprio `<script data-api>`. Melhoria progressiva: o `<input>` continua o mesmo, com o mesmo `name`; quem não está
   na lista é digitado inteiro. Atributos: `data-cx-suggest="person|email"`, `-multiple`, `-sources`, `-condominium`,
   `-unit`, `-fill-email`, `-fill-name`, `-id`. Sem condomínio no campo, vale o da barra superior do ERP
   (`<body data-cx-condominium>`). A partir da 3ª letra, espera 250 ms, guarda as respostas e filtra no navegador
   quando a lista anterior já veio completa. ARIA combobox/listbox, setas, Enter, Esc e Tab.
2. **Uma API só**: `GET /api/v1/suggest/people` (`Source\Services\Directory\PeopleSuggest`, OpenAPI). Fontes:
   - `residents`: `erp_residents` ativos dos condomínios do escopo (`CondominiumScope`), com `erp.access` e
     `erp.residents.view`; a mesma pessoa (documento ou e-mail) em várias unidades vira uma linha só;
   - `team`: contas com papel que tem `erp.access` (Gerente, Financeiro, Operador e Atendente têm `users.level` 2
     desde a ADR 0682) ou o nível legado de administrador (`level >= 5`); só para quem é da equipe.
3. **LGPD**: sai nome, contexto (vínculo, unidade e condomínio, ou "Equipe Connect") e o id da conta; o e-mail só
   quando o campo pede (`kind=email` ou `with=email`), e só nesses casos a busca casa pelo e-mail. CPF/CNPJ e telefone
   nunca saem. Cada busca vai para a auditoria (`people_suggest`) sem o texto digitado; limite de 900 por hora.
4. **Conta escolhida conferida no servidor**: quando a sugestão grava um id (solicitante do chamado no ERP e no Help
   Desk), `PeopleSuggest::accountAllowed()` só aceita se o e-mail enviado é o da conta e a conta é da equipe ou de um
   morador ativo do escopo; senão o chamado segue com nome e e-mail digitados. O responsável do Help Desk precisa ser
   da equipe (`isTeam`).

Campos ligados: chamado do ERP (nome e e-mail do solicitante), Help Desk › Novo chamado (solicitante, e-mail,
telefone e responsável, no lugar dos dois `<select>` com todas as contas), Proprietários e inquilinos (nome e e-mail,
pela chave `suggest` da definição do cadastro), Encomendas (quem retirou), Assembleia (quem está presente, pela unidade),
Atendimento de cobrança (com quem falou, pela unidade), Vistoria (destinatários, vários e-mails) e Usuários do ERP
(e-mail da conta, só equipe). Síndico e conselho ganha a sugestão de moradores pela thread própria (ADR 0686) e passa
para este componente depois do merge dela.

Fora: telas públicas e do morador (site, Central, app), que não buscam pessoas no banco; campos de e-mail que são
dados da própria Connect (remetente, encarregado, imobiliária nova, proposta para cliente novo).

## Consequências

- Campo novo de pessoa ou e-mail nasce com `data-cx-suggest`; não criar busca própria.
- Testes: `PeopleSuggestTest` (regras puras), `PeopleSuggestEverywhereTest` (componente, campos, rota e contrato) e
  `PeopleSuggestIntegrationTest` (escopo por condomínio, permissões, LGPD e conta conferida no MariaDB).
- Artigo da Central: `primeiros-passos/buscar-pessoas-e-emails`.
