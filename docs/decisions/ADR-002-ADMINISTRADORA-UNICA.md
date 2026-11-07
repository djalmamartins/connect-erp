# ADR-002 — Administradora única, sem SaaS

Status: decidido pelo proprietário (prompt mestre, 07/11/2026). Revoga as instruções de SaaS e multitenancy entre administradoras do pacote V3.

## Contexto
O pacote V3 e as fichas geradas da auditoria APControle partiam de uma plataforma SaaS com várias administradoras ("tenant"). O Connect ERP é um sistema privado usado só pela administradora Connect para gerir seus condomínios.

## Decisão
- Existe uma única administradora: a Connect. Não há tabela, cadastro ou `tenant_id` de administradoras.
- A unidade de segregação lógica é o **condomínio**. Todo dado operacional, financeiro e documental carrega `condominium_id` (FK, índice) e nunca se mistura entre condomínios.
- O acesso de cada usuário é limitado aos condomínios a que está vinculado; a verificação é feita no servidor (serviço de domínio + middleware), em consultas, comandos, exportações e arquivos.
- Usuários, papéis, permissões e logs do Organic Code são reutilizados (ADR-001) e ampliados com o vínculo usuário↔condomínio.

## Fora do escopo
Cadastro de administradoras clientes, planos e assinaturas, cobrança por administradora, provisionamento de empresas, marketplace e demais funcionalidades comerciais de plataforma. Fichas do APControle desse tipo ficam marcadas como FORA DO ESCOPO em `docs/specs/` (AP-169, AP-170, AP-172), e as que precisam de adaptação como REINTERPRETAR (AP-001, AP-148, AP-171).

## Consequências
- Testes de autorização cobrem acesso cruzado entre condomínios, não entre administradoras.
- Relatórios e consolidações da Connect podem agregar vários condomínios apenas para usuários com permissão explícita, sem misturar saldos ou lançamentos.
