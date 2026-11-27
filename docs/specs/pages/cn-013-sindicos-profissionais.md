# CN-013 — Síndicos profissionais

Origem: pedido do proprietário em 27/11/2026, nos testes de Síndico e conselho ("aonde tem o histórico embaixo, se a
gente ter como cadastrar síndicos profissionais, e aí a gente seleciona qual é o síndico profissional que está"). Não
existe no inventário APControle; é página própria da Connect. Decisão: ADR 0686.
Rota: `/erp/cadastros/sindicos-profissionais` (grupo Cadastros, logo depois de Síndico e conselho).
API: `/api/v1/professional-managers` (motor de cadastros, ADR 0014) e `GET /api/v1/board-members/candidates`.

## Regras implementadas

| Regra | Situação |
|---|---|
| Cadastro da administradora (escopo none, como fornecedores e advogados): vale para todos os condomínios, sem `condominium_id` | CONFIRMADO (ADR-002: Connect é a administradora única) |
| Campos: nome ou razão social*, CPF/CNPJ (busca do CNPJ na Receita), responsável, e-mail*, telefone*, observações, situação* | INFERIDO |
| CPF/CNPJ não se repete | CONFIRMADO |
| Só os ativos aparecem para escolher em Síndico e conselho | CONFIRMADO |
| Ligado a membro ativo de Síndico e conselho não pode ser excluído (ADR 0020); inativar mantém o histórico | CONFIRMADO |
| Permissões: as mesmas de Síndico e conselho (`erp.board.view` / `erp.board.manage`), sem permissão nova | CONFIRMADO (decisão para não mexer em papéis e permissões) |
| Auditoria em toda gravação | CONFIRMADO |

## PENDENTE

- Contrato e honorários do síndico profissional com cada condomínio (hoje ficam nas observações).
- Relatório "quais prédios cada síndico profissional atende" (dá para filtrar Síndico e conselho pelo nome).

## Estados

- Sem banco: tela indisponível do motor de cadastros.
- Lista vazia: "Nenhum síndico profissional cadastrado" e o botão de novo.
