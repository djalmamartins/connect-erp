# 0052 — Seletor do Disco Virtual no anexo dos cadastros (AP-126 a AP-129)

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

O ADR 0050 criou os avisos de recebimento, as cópias de cheques, os empenhos e os malotes, cada um
com um campo de anexo (`'attachment'`) que guarda o nº de um arquivo do Disco Virtual (ADR 0047) do
mesmo condomínio. As telas saem do motor de cadastros, e o anexo aparecia como um campo numérico:
era preciso abrir o Disco Virtual, achar o número e digitar.

## Decisão

- O formulário do cadastro troca o campo numérico do anexo por um seletor
  (`components/forms/registry-field.php`, quando `Registry::attachmentData` indica o campo). O nº
  continua num campo oculto com o mesmo nome; o servidor segue conferindo que o arquivo existe e é do
  condomínio do registro (`RegistryService::attachmentRules`).
- Mostra o arquivo anexado (título, nome e "Baixar" pela API do Disco Virtual). O título só aparece
  para quem tem `erp.documents.view`; sem essa permissão, aparece o nº e não há como escolher.
- **Escolher do Disco Virtual** (com `erp.documents.view`): modal com os arquivos do condomínio
  escolhido no formulário, com busca e filtro de pasta (`GET /api/v1/documents`).
- **Enviar arquivo** (com `erp.documents.manage`): no mesmo modal, envia ao Disco Virtual (pasta,
  título e arquivo, multipart com progresso) e já anexa. O envio usa o mesmo `sendMultipart` da tela
  do Disco Virtual.
- Trocar o condomínio do formulário remove o anexo escolhido, porque ele é de outro condomínio.
- O anexo só é gravado ao salvar o cadastro; o modal avisa isso.

## Consequências e lacunas

- Um arquivo enviado pelo modal e não usado (o cadastro não foi salvo) fica no Disco Virtual, como
  qualquer arquivo enviado lá.
- A lista do modal mostra os 50 mais recentes; para os demais, usa-se a busca.
- Testado sem MariaDB: render-smoke do formulário, teste unitário do componente e fluxo no navegador
  com mocks (escolher, enviar e anexar, troca de condomínio).
