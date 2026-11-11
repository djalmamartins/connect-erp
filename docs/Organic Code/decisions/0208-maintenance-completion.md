<!-- markdownlint-disable MD024 -->
# 0208 — Conclusão de manutenções com a próxima agendada

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0051 (cadastros operacionais, AP-110), 0202 (fornecedores), ADR-002 (escopo)

## Contexto

O cadastro de manutenções (AP-110) guarda o serviço, o fornecedor, a data prevista, a periodicidade e a conclusão.

A periodicidade, porém, é só um rótulo. Quem conclui a limpeza da caixa d'água semestral precisa lembrar de cadastrar
a próxima. A conclusão também é feita uma por uma, embora o zelador ou a administradora costume registrar várias de
uma vez (revisão do elevador, recarga dos extintores, dedetização).

Quando a próxima não é cadastrada, a manutenção obrigatória vence sem ninguém ver: laudo do elevador, AVCB, limpeza da
caixa d'água.

## Decisão

Rota nova: `POST /api/v1/maintenances/complete` com `{condominium_id, items[{id, done_on}], schedule_next?, dry_run?}`.
O service é `MaintenanceCompletion`. A rota fica antes do laço dos cadastros.

### Conclusão

Cada manutenção agendada ou em execução do condomínio é regravada pelo `RegistryService::save` só com `done_on` (até
hoje) e a situação "Concluída". Valem as regras do formulário, por exemplo fornecedor ativo.

### Próxima agendada

- **Quando:** só nas periódicas (mensal, trimestral, semestral, anual) e com `schedule_next`, que vem ligado por
  padrão.
- **O que cria:** a próxima, pelo formulário, com o mesmo serviço, fornecedor, periodicidade e descrição, sem anexo.
- **Data** (regra pura `nextPlannedOn`): a data prevista mais N períodos, a primeira depois da conclusão.
  - **Calendário do contrato:** feita adiantada ou no dia, a próxima segue o calendário.
  - **Atraso:** feita atrasada, pula o período perdido em vez de nascer já vencida.
  - **Fim de mês ajustado** (regra pura `addMonths`): 31/01 + 1 mês = 28/02, e a seguinte volta a 31/03 (sempre a
    partir da data prevista).
- **Sem duplicar:** se a mesma manutenção (serviço) já está agendada nessa data, não cria outra; o resultado aponta para
  ela (`already_scheduled`).

### Recusas

A regra pura `problem` recusa a manutenção não encontrada no condomínio, já concluída ou cancelada.

### Gravação

- **Tudo ou nada:** numa transação com as manutenções travadas (`FOR UPDATE`), uma recusa, aqui ou pelo formulário,
  desfaz todas. O 422 traz os erros e `_maintenance`.
- **Prévia:** `dry_run` passa por tudo e desfaz no fim.
- **Limite:** até 100 manutenções por vez; a mesma manutenção não se repete no pedido.
- **Auditoria:** a do formulário (`update` da concluída, `create` da próxima) e um registro `complete` por manutenção
  (situação anterior, data e próxima), só quando grava.
- **Nada é enviado.**

### Acesso

- **Escopo (ADR-002):** um condomínio do escopo; fora dele, 404.
- **Permissão:** exige `erp.access`, `erp.maintenances.view` e `erp.maintenances.manage`; sem uma delas, 403
  (fail-closed).
- **CSRF:** pelo `ApiAuthenticated`.

## Consequências

- **Testes.**
  - `Unit\MaintenanceCompletionTest` (SQLite):
    - fim de mês e ano bissexto;
    - próxima adiantada, no dia e atrasada, para cada periodicidade, e para "única" e periodicidade desconhecida;
    - recusas e itens;
    - validação, escopo (404) e contrato, sem `NotificationOutbox`;
    - controller e rota.
  - `Integration\MaintenanceCompletionTest` (MariaDB, banco `*_test`):
    - prévia sem gravar;
    - três concluídas: a semestral com a próxima criada; a mensal com 31/10 → 30/11, já agendada e não duplicada; a
      única sem próxima;
    - auditorias `complete`;
    - repetir é recusado;
    - fornecedor inativo recusado, com tudo ou nada;
    - cancelada e inexistente recusadas.
- **Rota e documentação no patch:** `index.php` e OpenAPI.
- **Sem migration e sem tabela nova.**
- **Sem tela nova neste patch.** Quando a tela existir (seleção e "Concluir" em Manutenções), ela ganha o artigo da
  Central de Ajuda.
