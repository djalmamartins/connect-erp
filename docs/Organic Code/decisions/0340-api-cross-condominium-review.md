# 0340 — Revisão de IDOR: registro de outro condomínio pela API

- Status: Aceito
- Data: 17/11/2026
- Pedido: `docs/ROADMAP_V1.md` §3.7 (acesso cruzado entre condomínios e IDOR); Djalma, 16/11/2026: "tome as melhores decisões"
- Relacionadas: ADR 0096 (fora do escopo dá 404), ADR 0150 (cobertura de guarda da API), ADR 0339 (anexos dos chamados)

## Contexto

A segregação é por `condominium_id` (ADR-002). Cada serviço confere o escopo ao carregar um registro pelo id, mas faltava
uma conferência que passasse por todas as rotas `GET /api/v1/.../{id}` de uma vez e que pegasse uma rota nova sem a trava.

## Decisão

`ApiCrossCondominiumIntegrationTest` chama, dentro do processo, as 27 rotas GET com `{id}` do ERP (condomínio, painéis,
unidade, fatura, boleto, publicação, importações, acordo, documentos, lançamento, cobrança extra, transferência, aviso,
assembleia, processo jurídico, vistoria, chamado):

- quem tem todas as permissões do ERP mas só enxerga o Alpha pede um registro do Beta: precisa receber 403 ou 404;
- controle positivo: quem enxerga todos os condomínios faz o mesmo pedido e não pode receber 401/403/404, senão a fixture
  não provaria nada.

As linhas do Beta são montadas a partir do `information_schema` (colunas obrigatórias com valores neutros), com unidade,
lançamento e conta bancária reais do Beta. Rota nova com `{id}` entra na lista `ROUTES` do teste.

## Resultado

Nenhum vazamento nas 27 rotas. Para conferir que o teste pega a falha, a trava de escopo de `EntryShortcuts::transferRow`
foi retirada por um instante: o teste falhou apontando a rota. A trava foi devolvida.

## Fora desta fatia

As rotas que alteram (POST/PATCH/DELETE com `{id}`), os downloads (`/documents/{id}/download`, anexos de lançamento) e as
rotas do app do morador (`/resident/...`, que filtram pela unidade do vínculo) ficam para a próxima fatia; a leitura do
código mostrou o mesmo padrão (carregar a linha e conferir o escopo antes de agir).
