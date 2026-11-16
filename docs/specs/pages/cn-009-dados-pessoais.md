# CN-009 — Dados pessoais (LGPD)

Origem: `docs/ROADMAP_V1.md` etapa 6 (LGPD), 16/11/2026. Não existe no inventário APControle; é página própria da Connect.
Rota: `/erp/configuracoes/dados-pessoais` (Configurações › Acesso). Decisões: ADR 0328, 0330 e 0332.
API: `POST /api/v1/privacy/personal-data`, `POST /api/v1/privacy/anonymize`.

## Regras implementadas

| Regra | Situação |
|---|---|
| Busca por CPF/CNPJ válido e/ou e-mail válido, só nos condomínios do escopo | CONFIRMADO |
| Busca por POST com CSRF; CPF e e-mail não vão na URL; página sem cache | CONFIRMADO |
| Mostra quantos registros há por grupo (cadastros, convites, visitantes, veículos, animais, contas, chamados, conta de acesso) e a lista dos cadastros | CONFIRMADO |
| Baixar o relatório completo em JSON | CONFIRMADO |
| Anonimizar só com `erp.users.manage`, sem bloqueio, com motivo de 10 a 500 caracteres e confirmação na modal do sistema | CONFIRMADO |
| Bloqueios: cadastro ainda ativo sem saída no passado; conta a receber em aberto com a pessoa como pagadora | CONFIRMADO |
| Consultar exige `erp.access` + `erp.privacy.export`; limite de 20 buscas e 10 anonimizações por 10 minutos | CONFIRMADO |
| Auditoria só com o hash da busca, quantidades e motivo | CONFIRMADO |

## PENDENTE

- Texto da política de privacidade e nome do encarregado de dados (dependem do proprietário).

## Estados

- Sem banco: "Dados pessoais indisponíveis".
- Busca sem resultado: "Nada encontrado".
- Com bloqueio: aviso com os motivos, sem botão de anonimizar.
- Sem `erp.users.manage`: só relatório e download.
