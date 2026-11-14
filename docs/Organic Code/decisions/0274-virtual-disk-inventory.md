<!-- markdownlint-disable MD024 -->
# 0274 — Inventário do Disco Virtual

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0047 (Disco Virtual, AP-122);
  - 0091 (integrações, que conta o antivírus só no total do escopo);
  - 0098 (antivírus no envio do Disco Virtual).

## Contexto

O Disco Virtual (AP-122, ADR 0047) lista arquivo por arquivo numa pasta. A tela de integrações (ADR 0091) conta o
antivírus só no total do escopo.

A administradora não vê, de uma vez, para cada condomínio:

- quantos arquivos e quanto espaço cada pasta ocupa;
- quantos passaram ou não pelo antivírus (ADR 0098);
- quantos foram enviados nos últimos meses;
- quantas cópias do mesmo arquivo estão em pastas diferentes (o mesmo conteúdo só é recusado dentro da mesma pasta);
- quanto ficou na lixeira (a exclusão é lógica: o arquivo continua guardado).

Busquei `erp_documents` em `source/`, no README das ADRs e nos handoffs 02xx. Usam a tabela o Disco Virtual, a tela de
integrações, a nova verificação do antivírus e os serviços que conferem documento de contrato, seguro, manutenção e
empenho. Nenhum faz o inventário por condomínio.

## Decisão

Rota nova: `GET /api/v1/reports/virtual-disk?condominium_id=`, logo depois de `POST /cost-centers/deactivate-idle` e antes
do laço dos cadastros. O service é `VirtualDiskInventory`, em `Source\Services\Erp`.

### Reuso

- **Pastas:** `VirtualDisk::FOLDERS`.
- **Antivírus:** as situações de `ScanStatus::ALL`; uma situação desconhecida conta como `error`.
- **Mesmo conteúdo:** o `sha256`, a mesma identidade da recusa de duplicado.

### Regra pura `ageBucket()` e `summarize()`

- **`rows`:** uma linha por pasta, sempre todas as de `VirtualDisk::FOLDERS` (seis desde a ADR 0174: atas, contratos, plantas, notas fiscais, comprovantes e outros), com `documents`, `bytes` e o antivírus (somando `documents`), só dos não
  excluídos. `totals` soma as linhas.
- **`by_age`:** os não excluídos pelo dia do envio: `ate_90`, `de_91_a_365` e `mais_de_365` dias, somando
  `totals.documents`. Envio com data futura (relógio) conta como recente.
- **`duplicates`:** conteúdos com mais de um arquivo não excluído: `groups`, `extra_copies` (as cópias além da primeira) e
  `extra_bytes`.
- **`trash`:** os excluídos (`documents` e `bytes`).

### Acesso e dados

- **Permissão:** exige `erp.access` e `erp.documents.view`, a mesma da lista do Disco Virtual. Sem uma delas, 403
  (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422.
- **Somente leitura:** não grava, não audita, não abre nenhum arquivo e não envia nada.
- **Dados que saem:** só pastas e contagens. Não saem título, nome de arquivo, caminho, hash ou quem enviou.
- **Não toca** o portal do morador, o app, a sessão por token, o login nem o 2FA.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\VirtualDiskInventoryTest`:**
  - as faixas de idade com as bordas de 90 e 365 dias e o envio no futuro;
  - as pastas do Disco Virtual, o antivírus e a situação desconhecida;
  - as cópias entre pastas e a lixeira;
  - totais iguais à soma das linhas;
  - outro condomínio de fora;
  - nenhum título, nome, caminho ou hash;
  - a validação, o escopo, o contrato, o controller e a rota.
- **`Integration\VirtualDiskInventoryTest`** (MariaDB, banco `*_test`):
  - envios de verdade pelo Disco Virtual, inclusive o mesmo PDF em duas pastas;
  - uma exclusão lógica na lixeira;
  - um envio de 400 dias atrás;
  - somente leitura e sem título, nome ou hash;
  - o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda. O Disco Virtual pode ganhar o quadro do inventário;
  quando ganhar, vem o artigo.
