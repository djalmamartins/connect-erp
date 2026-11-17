# 0351 — Dados do titular também na portaria, mudanças, reservas, assembleias e cobrança

- Status: Aceito
- Data: 17/11/2026
- Contexto: lacuna achada no registro LGPD (ADR 0350); amplia os ADRs 0328 (relatório) e 0330 (anonimização)

## Contexto

O relatório do titular (Configurações > Dados pessoais) e a anonimização de quem saiu cobriam:
cadastros, convites, visitantes, veículos, animais, contas a receber, chamados e a conta de acesso.

Outros registros também guardam dado pessoal e ficavam de fora:
- a portaria (entradas e saídas, visitas previstas), que guarda nome e documento;
- o conselho, que guarda nome, documento, e-mail e telefone;
- mudanças, reservas, lista de espera, presença em assembleia e contatos de cobrança. Esses guardam só o nome
  digitado na unidade.

## Decisão

**Relatório** (`PersonalDataExport`). Grupos novos:
- `access_logs` e `visit_forecasts`, pelo documento, com ou sem pontuação;
- `board_members`, pelo documento ou pelo e-mail;
- `moves`, `reservations`, `waitlist`, `assembly_attendance` e `collection_contacts`, quando o nome é igual ao
  do cadastro na mesma unidade. A comparação ignora acento e maiúscula, igual à usada para veículos e animais.

Em todos, anotações internas (`notes`, `note`) não saem.

**Anonimização** (`PersonalDataAnonymizer`):
- Na portaria, nas mudanças, reservas, lista de espera e contatos de cobrança, o nome vira "Titular anonimizado" e
  o documento e as anotações são apagados.
- Datas, unidade, placa e valores ficam.
- Cargos no conselho e presença em assembleia **não mudam**, porque fazem parte da ata e do mandato, que o
  condomínio precisa guardar. A prévia mostra quantos ficam, em `kept`.

**Telas e documentação:** tela de Dados pessoais, OpenAPI, artigo e explicação da Central de Ajuda, registro LGPD.

## Testes

- `PersonalDataExportTest::testFindsGatehouseBoardAndNameOnlyRecords` confere:
  - a busca pelo documento, com ou sem pontuação;
  - a busca pelo e-mail;
  - o mesmo nome na mesma unidade;
  - o escopo do condomínio, inclusive o da assembleia;
  - que anotações não saem.
- `PersonalDataAnonymizerTest::testAnonymizesGatehouseAndNameOnlyRecordsButKeepsMinutes` confere:
  - o que muda;
  - o que fica (placa, valores, conselho, ata);
  - o outro condomínio, fora do escopo.

## Lacunas

- Talk (`talk_contacts`): a busca seria pelo telefone. Fica para depois.
- Nome igual de duas pessoas na mesma unidade cai no mesmo titular. É o mesmo risco que já existia em veículos e
  animais: a equipe confere a prévia antes de confirmar.
