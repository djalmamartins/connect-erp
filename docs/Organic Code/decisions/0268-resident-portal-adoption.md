<!-- markdownlint-disable MD024 -->
# 0268 — Adesão ao portal do morador

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0090 (convite do morador e matriz do portal, AP-152 e AP-153);
  - 0093 (sem CPF/CNPJ válido não há convite);
  - 0265 (regra de morador ativo usada aqui).

## Contexto

O convite do morador (AP-152) lista convite por convite, com nome e e-mail mascarado, e a matriz do portal (AP-153) mostra
quais funções estão ligadas.

A administradora não vê, de uma vez:

- quantos moradores ativos já entraram no portal;
- quantos têm convite pendente, vencido ou revogado;
- quantos ainda não foram convidados e o que falta para convidar (documento válido e e-mail);
- quantas unidades habitadas têm alguém com acesso.

Ela usa isso na campanha de adesão e para decidir se vale ligar mais funções do portal.

Busquei `erp_resident_invitations` e `erp_resident_portal_permissions` em `source/`, no README das ADRs e nos handoffs
02xx. Usam as tabelas o convite, o aceite, a saída da unidade (0206), o encerramento dos vínculos vencidos (0241) e a
matriz do portal. Nenhum relatório conta a adesão.

## Decisão

Rota nova: `GET /api/v1/reports/resident-portal?condominium_id=`, logo depois de `POST /vehicles/deactivate-unattached` e
antes do laço dos cadastros. O service é `ResidentPortalAdoption`, em `Source\Services\Erp`.

### Reuso

- **Morador ativo:** a regra das listas do cadastro (vínculo ativo, sem excluído e sem fim de vínculo passado), como no
  0265.
- **Vínculos:** os do cadastro (`RegistryService::definition('residents')`).
- **Convite vencido:** a regra da lista dos convites (pendente com a validade passada).
- **Documento:** `InvitationRules::validDocument` (ADR 0093).
- **Ordem do convite:** sem documento válido a lista inteira é recusada, e sem e-mail o morador fica de fora; por isso
  `no_document` vem antes de `no_email`.
- **Funções do portal:** `ResidentPortalPermissions::FEATURES` e `enabled()`, que é fail-closed.

### Regra pura `classify()` e `summarize()`

Cada morador ativo cai numa situação:

- `accepted`: já aceitou um convite, mesmo que um convite depois tenha sido revogado;
- `pending`: o convite mais recente está pendente e dentro da validade;
- `expired`: o convite mais recente está pendente e venceu;
- `revoked`: o convite mais recente foi revogado;
- `ready`: nunca convidado, com documento válido e e-mail;
- `no_document`: nunca convidado, sem CPF/CNPJ válido;
- `no_email`: nunca convidado, com documento válido e sem e-mail válido.

Uma linha por situação, sempre as sete, com `residents` e `by_relationship` somando a linha.

- **`totals`:** `residents` é a soma das linhas, `with_access` = `accepted` e `to_invite` = `ready` + `expired` +
  `revoked`.
- **`units`:** as unidades vinculadas com morador ativo (`inhabited`), separadas em `with_access` e `without_access`, que
  somam `inhabited`.
- **`features`:** todas as funções do portal (as de `ResidentPortalPermissions::FEATURES`; nove desde a ADR 0302), ligadas ou não; `enabled` conta as ligadas.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.access_admin.view` e `erp.residents.view`, as mesmas da lista dos convites. Sem
  uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** só situações, vínculos, funções e contagens. Não saem nome, documento, e-mail, telefone, unidade ou
  token.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\ResidentPortalAdoptionTest`:**
  - a regra pura das sete situações, com o convite mais recente e o aceite que vale;
  - documento antes de e-mail;
  - totais iguais à soma das linhas e vínculos somando cada linha;
  - morador inativo, com fim de vínculo passado, excluído e de outro condomínio de fora;
  - as funções do portal do próprio condomínio;
  - nenhum dado pessoal;
  - a validação, o escopo, o contrato, o controller e a rota.
- **`Integration\ResidentPortalAdoptionTest`** (MariaDB, banco `*_test`):
  - as situações com os cadastros e os convites;
  - as unidades com e sem acesso e a função ligada;
  - somente leitura e sem dado pessoal;
  - o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda. A tela dos convites pode ganhar o quadro da adesão;
  quando ganhar, vem o artigo.
