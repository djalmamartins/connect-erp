# Decisões (ADRs) — índice único

O projeto tem duas séries de ADR por motivo histórico. Elas **não serão renumeradas**:
os nomes atuais são citados em código, migrations, specs e PRs, e renomear quebraria essas
referências. Este arquivo é o índice único das duas (issue #9).

## Regra para novas decisões

- Toda decisão nova vai em `docs/Organic Code/decisions/` com o próximo número de 4 dígitos
  (`NNNN-titulo-em-kebab-case.md`). **Próximo número livre: 0017.**
- Antes de criar, confira este índice e o diretório: duas threads já usaram o mesmo número
  em paralelo (0010). Ao criar, acrescente a linha na tabela abaixo no mesmo commit.
- A série `ADR-00N` de `docs/decisions/` fica congelada: são as decisões de fundação do
  produto (núcleo, administradora única, design system). Não criar ADR-004.

## Fundação (`docs/decisions/`)

| ADR | Decisão |
|---|---|
| [ADR-001](ADR-001-EXISTING-CORE.md) | Reutilizar núcleo de identidade e auditoria |
| [ADR-002](ADR-002-ADMINISTRADORA-UNICA.md) | Administradora única, sem SaaS |
| [ADR-003](ADR-003-STUDIO-DESIGN-SYSTEM.md) | Design System do Studio em camada única |

## Implementação (`docs/Organic Code/decisions/`)

| ADR | Decisão |
|---|---|
| [0001](../Organic%20Code/decisions/0001-local-environment.md) | Ambiente local na stack XAMPP compartilhada |
| [0002](../Organic%20Code/decisions/0002-helpdesk-support-themes.md) | Help Desk e Central de Suporte como temas próprios |
| 0003 | Não existe: o ADR de assets foi renumerado para 0004 (`5e8ddbd`) |
| [0004](../Organic%20Code/decisions/0004-organic-shared-assets.md) | Distribuição Organic em `container/shared` e fim de `organic/` e `src/` |
| [0005](../Organic%20Code/decisions/0005-legacy-images-storage.md) | Imagens do legado no storage, fora do Git |
| [0006](../Organic%20Code/decisions/0006-fail-closed-access-and-offline-unit-tests.md) | Autorização fail-closed e suíte unit sem MariaDB |
| [0007](../Organic%20Code/decisions/0007-erp-theme-and-condominiums-slice.md) | Tema ERP e primeira fatia de Condomínios |
| [0008](../Organic%20Code/decisions/0008-send-mail-templates.md) | Templates de e-mail no ambiente `send` |
| [0009](../Organic%20Code/decisions/0009-password-reset-tokens.md) | Recuperação de senha com token aleatório, hash e uso único |
| [0010](../Organic%20Code/decisions/0010-erp-frontend-shell-and-catalog.md) | Moldura do ERP, catálogo de rotas e login compartilhado |
| [0011](../Organic%20Code/decisions/0011-condominium-registry.md) | Cadastro e edição de condomínios (AP-004) |
| [0012](../Organic%20Code/decisions/0012-single-database.md) | Banco único (C5) |
| [0013](../Organic%20Code/decisions/0013-condominium-onboarding-checklist.md) | Checklist de chegada do condomínio |
| [0014](../Organic%20Code/decisions/0014-registry-engine.md) | Motor único dos cadastros do ERP |
| [0015](../Organic%20Code/decisions/0015-inspections-gps-photos.md) | Vistoria presencial com GPS e fotos |
| [0016](../Organic%20Code/decisions/0016-condominium-areas-assets.md) | Áreas comuns, áreas vinculáveis e patrimônio pelo motor de cadastros |
