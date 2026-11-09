# 0074 — Telas de importação: envio, prévia por linha, aplicar e fila do extrato

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Backend: ADR 0058

## Contexto

O ADR 0058 criou as importações com prévia, validação do arquivo inteiro e idempotência por hash:
- o extrato OFX, que só concilia;
- as planilhas CSV/XLSX de lançamentos, lançamentos por unidade, contas a pagar, inadimplentes e
  frações ideais;
- as leituras.

As páginas do catálogo (AP-140, AP-142 a AP-146 e AP-044) ainda mostravam a demonstração.

## Decisão

- **Uma tela para todos os tipos** (`pages/imports/import`). `ImportScreens` lê os dados no servidor
  pelo mesmo serviço da API, e a página do catálogo define o tipo (`Imports::KINDS[...]['page']`).
- **Enviar:**
  - formulário com o arquivo (e a conta bancária, no OFX), enviado em multipart para
    `POST /api/v1/imports/preview`, com CSRF no cabeçalho e barra de progresso;
  - a tela confere o formato e o tamanho (2 MB) antes de enviar;
  - mostra as colunas obrigatórias e opcionais do tipo e oferece um modelo CSV com o cabeçalho
    (`ImportScreens::templateHeader`, separado por `;`).
- **Prévia:**
  - a resposta da API vira uma tabela linha a linha, com a situação (pronta, com erro, já importada,
    substitui a atual), o conteúdo resumido e cada erro;
  - com erro, o filtro "só as linhas com erro" já vem marcado e o botão Aplicar não aparece;
  - o resumo mostra o total, a soma das frações, o consumo ou as entradas e saídas, e o aviso de
    saldo do OFX que não confere com o do sistema.
- **Aplicar** (`POST /api/v1/imports/{id}/apply`):
  - pede confirmação e abre o detalhe da importação (`?importacao=ID`), com o que foi gravado;
  - uma importação em prévia sem erro pode ser aplicada também pelo detalhe;
  - a prévia linha a linha não fica guardada, então o detalhe orienta reenviar o mesmo arquivo, que
    volta para a mesma importação.
- **Fila do extrato (AP-140):**
  - mostra as linhas pendentes (sem lançamento ou com mais de um candidato) do condomínio da barra
    superior;
  - "Resolver" (`?linha=ID#conciliar`) abre o painel com os lançamentos candidatos;
  - `StatementLineCandidates` busca os candidatos, só para leitura: pagos na mesma conta, do mesmo
    sentido e valor, ainda não conciliados, do mais próximo da data ao mais distante, até 20;
  - o usuário escolhe um candidato ou "Ignorar esta linha", com nota, e a API
    (`POST /bank-statement-lines/{id}/resolve`) confere tudo de novo;
  - a tela nunca escolhe sozinha: com um candidato só, ele vem marcado; sem candidato, vem marcado
    "Ignorar".
- **Gravar exige um condomínio na barra superior** e as permissões da API:
  - `erp.imports.manage` mais a do destino;
  - resolver linha exige `erp.entries.manage`.
- O menu usa `erp.imports.view`.

## Consequências

- A NF-e (AP-141) segue com a demonstração; ficou fora do ADR 0058.
- Não foi testado com MariaDB real. Os testes cobrem a renderização (smoke), as permissões e o fluxo
  no navegador com a API simulada (prévia com erro, filtro e erro ao resolver).
