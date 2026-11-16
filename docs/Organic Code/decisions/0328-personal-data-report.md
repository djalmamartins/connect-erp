# 0328 — Relatório dos dados pessoais de um titular (LGPD)

- Status: Aceito (etapa 1: acesso aos dados; anonimização fica para a etapa 2)
- Data: 16/11/2026
- Pedido: `docs/ROADMAP_V1.md` etapa 6 (LGPD: exportar e anonimizar dados de uma pessoa); Djalma, 16/11/2026: "tome as melhores decisões"
- Relacionadas: ADR-002 (escopo por condomínio), 0168 (usuários do ERP), 0326 (limite por IP)

## Contexto

A LGPD dá ao titular o direito de saber quais dados o sistema guarda dele (art. 18, II). Hoje a equipe teria de abrir tela por
tela. Os dados de uma pessoa ficam espalhados: cadastro de morador (um por condomínio e unidade), convites do portal, visitantes,
veículos e animais (o cadastro antigo guarda só o nome do dono), contas a receber em que ela é a pagadora, chamados e a conta
de acesso.

## Decisão

1. `POST /api/v1/privacy/personal-data` com `document` (CPF/CNPJ) e/ou `email`; POST para o documento não ir para a URL nem
   para o log de acesso. Serviço `Source\Services\Privacy\PersonalDataExport`.
2. Permissão nova `erp.privacy.export` (migration `20261116_2130`), dada aos papéis com `erp.users.manage`. Limite de 20 buscas
   por 10 minutos.
3. Escopo (ADR-002): só os condomínios de quem pede. A conta de acesso (`users`) só aparece quando um cadastro do escopo aponta
   para ela ou quando quem pede acessa todos os condomínios.
4. Veículos e animais entram quando estão na mesma unidade e o dono/tutor tem o mesmo nome do cadastro (sem acento, caixa e
   espaço extra). É INFERIDO: o cadastro antigo não liga veículo e animal à pessoa.
5. Nunca sai senha, hash, token, segredo de 2FA nem anotação interna (`notes`). Até 500 linhas por grupo, com `truncated`.
6. Auditoria `export` em `privacy_personal_data` só com o sha256 da busca e o total de registros; nenhum dado pessoal no log.

## Consequências

- A equipe atende o pedido de acesso numa busca e entrega o JSON (ou o PDF, quando houver tela) ao titular depois de conferir
  a identidade fora do sistema.
- PENDENTE (etapa 2): anonimizar quem saiu, respeitando a guarda obrigatória dos dados financeiros (contas, boletos e
  prestação de contas ficam; nome, documento, e-mail e telefone viram "Titular anonimizado"), e a tela em Configurações com
  artigo na Central de Ajuda.
