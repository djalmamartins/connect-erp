# Claude Code — Organic Code / Connect ERP

> O contrato original da base foi preservado em
> `docs/reference/ORGANIC_CODE_ENGINEERING_CONTRACT.md` e continua obrigatório.
> O runtime MovesCode ainda não está presente nesta fundação; não invente
> substitutos nem declare recursos existentes sem evidência no repositório.

## Missão
Transformar a auditoria APControle em produto próprio Connect ERP, com implementação independente, incremental e verificável. O PDF é referência de requisitos, NÃO prova de fluxos completos.

## Ordem de leitura obrigatória
1. `docs/context/PROJECT.md` e `docs/context/EVIDENCE_POLICY.md`
2. `docs/architecture/ARCHITECTURE.md` e `docs/architecture/DESIGN_SYSTEM.md`
3. `docs/specs/INDEX.md` e a spec da página em execução
4. `docs/process/WORKFLOW.md` e `docs/process/DEFINITION_OF_DONE.md`
5. `docs/security/SECURITY.md` e `docs/quality/TEST_STRATEGY.md`

## Regras inegociáveis
- Inspecionar o repositório Organic Code real antes de propor alterações; não assumir que pastas, framework, bibliotecas ou tabelas existem.
- Não sobrescrever o projeto, remover arquivos, alterar produção, executar migrations destrutivas ou usar credenciais reais sem aprovação explícita.
- Nunca copiar código, assets proprietários ou identidade visual do APControle; desenvolver solução independente.
- Uma spec por rota de referência; um componente compartilhado para comportamentos repetidos. Skills são processos, não cópias de páginas.
- Não implementar suposições como fatos: distinguir CONFIRMADO, INFERIDO e PENDENTE; abrir decisões em `docs/decisions/`.
- Autorização server-side, isolamento por administradora/condomínio, auditoria, LGPD e testes são obrigatórios.
- Cada tarefa: plano curto → implementação → testes → revisão → documentação → relatório de cobertura.
- Proibido afirmar '100% sem falhas'; informar testes executados, riscos e lacunas.
- Antes de integrar ferramentas MCP, ler `.mcp.example.json` e configurar apenas servidores instalados, com credenciais locais fora do Git.

## Contrato de entrega
Implementar por fatias verticais, reutilizando autenticação, usuários, permissões e logs já funcionais; validar contratos e complementar lacunas sem recriar esses módulos; priorizar condomínios, unidades, pessoas, MariaDB e núcleo financeiro antes das integrações bancárias. Não criar telas órfãs sem dados, autorização e testes.

## Decisões adicionais do proprietário (07/11/2026)
- Logs, usuários do sistema e permissões **já existem e funcionam no Organic Code**. NÃO recriar esses três módulos nem tratá-los como bloqueios do APControle. Fazer análise de compatibilidade e apenas extensões estritamente necessárias.
- Persistência alvo: **MariaDB**; não substituir banco existente sem plano de migração aprovado.
- Integração alvo: **API REST** (hipótese de interpretação do termo informado; confirmar biblioteca/linguagem/contrato na auditoria do repositório antes de codificar). Preferir contratos OpenAPI e geração de clientes se compatíveis com stack atual. Não confundir API REST com nome do produto Connect ERP.
- Cobrança bancária é requisito essencial: emissão/registro de boletos, Pix quando suportado, consulta, baixa, cancelamento, CNAB remessa/retorno quando necessário, webhooks, conciliação e homologação por banco. NÃO usar ambiente bancário real nem emitir títulos sem aprovação explícita.
- Ler `docs/architecture/MARIADB_REST.md`, `docs/banking/BANKING_INTEGRATIONS.md` e `docs/process/REUSE_EXISTING_MODULES.md`.


## Atualização V3 — decisão mais recente, prevalece sobre V2
- Banco obrigatório: **MariaDB**, não PostgreSQL.
- Interface de integração obrigatória: **API REST com JSON/OpenAPI**, não ConnectRPC.
- Desenvolver API REST **simultaneamente a cada página/módulo**, não como fase final.
- Seguir `docs/architecture/MARIADB_REST.md` e `docs/process/VERTICAL_SLICE_MARIADB_REST.md`.
- Preservar usuários, permissões e logs existentes; ampliar somente onde necessário.
- Integrações bancárias devem ser previstas desde o núcleo financeiro e implementadas por adaptadores, com testes em sandbox antes de homologação real.
