# ADR 0155 — Ficha da pessoa, somente leitura, no painel da unidade

- Situação: aceita (10/11/2026)
- Contexto: pedido do Djalma em 10/11 ("quando eu clicasse no nome da pessoa, poderia abrir um modal com todas
  as informações da pessoa, CPF, com os dados dela, de forma que não desse para alterar")

## Contexto

CONFIRMADO: o painel da unidade (AP-003, ADR 0054/0071) listava as pessoas ativas só com nome, telefone e e-mail.
Para ver CPF/CNPJ, vínculo, observações ou as outras unidades da pessoa era preciso sair para o cadastro
Proprietários e inquilinos (AP-005), que abre a edição.

CONFIRMADO: `erp_residents` não tem endereço próprio da pessoa (só o condomínio tem CEP, rua, número etc.).

## Decisão

- O nome de cada pessoa no bloco **Pessoas** vira um botão que abre um `<dialog>` (componente
  `components/modals/person-card.php`, mesmo `erp-modal` e `data-erp-modal-open` do design system).
- A ficha não tem formulário nem campo editável: CPF/CNPJ formatado, tipo (física/jurídica), vínculo, telefone
  (link `tel:`), e-mail (link `mailto:`), início e fim do vínculo, boleto por e-mail, comunicados, se tem acesso ao
  portal do morador, a unidade e o **endereço da unidade** (montado do endereço do condomínio), as **outras
  unidades** da pessoa e as observações. O rodapé tem **Abrir cadastro para alterar**, que leva ao AP-005.
- `PanelSummaries::unit()` (o mesmo serviço de `GET /api/v1/panels/unit/{id}`) passa a devolver esses campos em
  `people[]` e `unit.address`. Não há permissão nova: o painel já exige `erp.residents.view`, que é a mesma
  permissão que mostra esses dados no cadastro.
- **Outras unidades**: mesmo CPF/CNPJ (regra do ADR 0162), vínculo ativo, só em condomínios do escopo do usuário
  (`CondominiumScope::allowedIds`). Pessoa sem documento não é reconhecida em outras unidades.
- A ficha é renderizada no servidor junto com o painel (no máximo 50 pessoas por unidade); não há chamada extra.

## Consequências

- Atendimento por telefone acha o contato sem risco de alterar o cadastro sem querer.
- PENDENTE: endereço de correspondência próprio da pessoa (proprietário que não mora na unidade). Exige coluna
  nova em `erp_residents` e campo no cadastro; fica para quando for pedido.
- Testes: `PanelSummariesTest::testUnitAddressLine`, `PanelSummariesIntegrationTest::testPersonCardShowsRecordAndOtherUnitsInScope`
  e o smoke AP-003 em `tests/erp/render-smoke.php` (ficha sem `input/select/textarea/form`).
