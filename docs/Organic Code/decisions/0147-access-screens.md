# ADR 0147 — Telas de acesso de imobiliárias e moradores ligadas à API

- Situação: aceita (10/11/2026)
- Contexto: serviços e API do ADR 0090 (`AccessAdmin`), endurecidos nos ADR 0093, 0094 e 0111

## Contexto

AP-148 Usuários de imobiliárias, AP-152 Criação de usuários para moradores e AP-153 Permissões de Moradores
seguiam com a demonstração, embora `PartnerAccess`, `ResidentInvitations` e `ResidentPortalPermissions` e as
rotas de `/api/v1` já existissem.

## Decisão

- `AccessScreens` lê pelos mesmos serviços e entrega o template único `pages/settings/access`, com três modos:
  - **AP-148:** a lista de imobiliárias de `PartnerAccess::listAgencies` (contatos mascarados) com os usuários e
    os condomínios concedidos dentro do escopo de quem consulta; cadastrar, desativar, vincular, conceder,
    revogar e desvincular. O seletor de "Vincular usuário" já aplica as mesmas regras de `linkUser` (nível
    comum, sem papel, sem acesso, sem permissão própria, sem vínculo, nunca quem concede), para a pessoa não
    escolher uma conta que a API vai recusar; a API continua sendo quem decide.
  - **AP-152:** pede um condomínio; convites de `ResidentInvitations::list` e o convite em lote dos moradores
    ativos (sem e-mail aparece desabilitado). Exige também `erp.residents.view`, como a API.
  - **AP-153:** pede um condomínio; a matriz de `ResidentPortalPermissions::matrix`, uma caixa por função, que
    grava na hora e volta ao estado anterior se a API recusar.
- Gravações pelo erp.js (`data-erp-access`) na própria API, com o CSRF no cabeçalho.
- Permissões: menu com `erp.access_admin.view`; ações com `erp.access_admin.manage`.

## Consequências

- Os artigos da Central de Ajuda das três páginas passam a `situacao: funciona`; os prints ainda são da
  demonstração até rodar `bash tools/help/prints.sh` no Mac.
- Testes: `AccessScreensIntegrationTest` (usuários livres para vincular, contatos mascarados, convites e
  portal pedindo condomínio, portal desligado por padrão, sem permissão) e o smoke das três telas.
- A conta do usuário parceiro continua sendo criada em Studio › Usuários; esta tela não cria login nem senha.
