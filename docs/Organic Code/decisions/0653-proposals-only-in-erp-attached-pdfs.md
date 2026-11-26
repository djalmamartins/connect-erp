# 0653 — Propostas só no ERP: cadastrar, anexar os PDFs prontos e enviar

- Status: Aceito
- Data: 26/11/2026
- Relacionados: ADR 0639 (propostas em Studio > Gestão), 0644 (propostas do site no ERP, CN-011), 0604 (aceite no
  pedido de proposta, LGPD), 0098 (antivírus no upload), 0628 (envio de arquivos padrão), 0391 (PDF com dados
  pessoais sem cache), 0397 (e-mails na moldura Connect), 0141 (Central de Ajuda).
- Numeração: faixa avulsa 0600–0799; 0649 já foi usada duas vezes (CondoConta e link do chamado do Talk). Conferidos
  índice e branches em 26/11.

## Contexto
O Djalma pediu (26/11) que todas as funções de proposta fiquem dentro do ERP: criar, gerar e enviar. Até aqui o ERP
(ADR 0644) só listava e acompanhava; o PDF era gerado e enviado no Studio, e não havia como cadastrar uma proposta
pedida por telefone ou WhatsApp. Ele mandou o modelo que a Connect usa (PDF do Canva). Foi feita uma prévia da geração
nesse modelo, mas ele decidiu: "ao invés de você criar, deixa a gente anexar", "até dois arquivos, um para
administradora e outro para síndico". O sistema não gera mais o PDF. Pediu ainda que propostas fiquem só com
administração e desenvolvimento e uma conta por unidade "para conferência, e ver se compensa".

## Decisão
1. **Tudo no ERP (CN-011, agora "Propostas")**, pelo serviço `ProposalComposer`:
   - `GET/POST /comunicacao/propostas/nova`: cadastra a proposta com nome, perfil, e-mail, telefone, condomínio,
     unidades, necessidade e **como chegou** (telefone, WhatsApp, e-mail, indicação, visita, outro). Entra como
     Contatada, com quem cadastrou como responsável, sem e-mail para a pessoa e sem acender o alerta de "nova".
   - `POST /comunicacao/propostas/{id}/resposta` (multipart): **até dois PDFs**, `pdf_administrator`
     (Administradora) e `pdf_syndic` (Síndico profissional), pelo menos um; assunto, validade e mensagem opcional.
     "Só anexar" guarda; "Anexar e enviar" (com confirmação) manda **um e-mail** com os anexos e a situação vira
     Proposta enviada (salvo Fechada, Perdida ou Arquivada). Cada arquivo vira uma linha em `proposal_responses`
     (`template_type` = o tipo; apresentação = mensagem; escopo e condições vazios).
   - `POST /comunicacao/propostas/{id}/reenviar`: manda de novo um PDF já anexado.
   - `GET /comunicacao/propostas/{id}/pdf/{versão}`: abre o PDF só se a versão for daquela proposta e o arquivo
     estiver em `storage/files/proposals` (sem cache).
2. **Arquivo**: só arquivo que chegou pelo formulário (`is_uploaded_file`), só PDF (começa com `%PDF-` e o tipo
   detectado é `application/pdf`), até 20 MB cada (o PDF da Connect tem ~7 MB; o servidor aceita 50 MB por envio),
   antivírus pela política do ADR 0098 (`ERP_AV_MODE`). O campo usa o envio padrão do ADR 0628 (`data-max-mb`).
3. **Studio só consulta.** A tela do Studio mostra dados, situação, responsável e versões, com "Abrir no ERP". O POST
   antigo responde "agora é no ERP" (com CSRF) e não grava nada. Nenhum dado é apagado ou migrado: é a mesma tabela.
   O aviso do sino e o e-mail interno de proposta nova passam a abrir a proposta no ERP.
4. **Sem geração de PDF.** `ProposalPdf` (gerador antigo do Studio) foi removido. Os rótulos de `template_type`
   passam a ser Administradora (`administrator`) e Síndico profissional (`syndic`); nas versões antigas do Studio o
   rótulo antigo era o público (síndico e condomínio / administradora parceira).
5. **LGPD.** Cadastrar exige marcar que a pessoa pediu a proposta e aceitou o contato (base legal: procedimentos
   preliminares a contrato, a pedido do titular). A equipe nunca marca aceite de novidades. Guarda igual à dos pedidos
   do site (Política de Privacidade). PDF servido com `Cache-Control: private, no-store`. As fotos do modelo do Canva
   são da Connect dentro do Canva; como o arquivo é feito e exportado lá, a licença fica no uso do próprio Canva.
6. **Migration `20261126_0900_proposals_origin`** (idempotente): `proposals.origin` (padrão `site`) e
   `proposals.created_by`; garante `proposal_responses` e as colunas de anexo do `mail_queue`, que antes só o módulo do
   Studio criava. A reversão tira só as duas colunas novas.
7. **API REST junto**: `POST /api/v1/proposals` (cadastrar) e `POST /api/v1/proposals/{id}/responses` (multipart,
   anexar e, com `send=1`, enviar). OpenAPI atualizado.
8. **Conta da proposta** (`POST /comunicacao/propostas/{id}/calculo`): preço por unidade da administradora e do
   síndico profissional e o mínimo mensal que compensa (colunas `price_admin_unit_cents`, `price_syndic_unit_cents`,
   `min_monthly_cents`). A tela mostra o total de cada serviço no mês (preço × unidades do pedido), o total do mês e do
   ano e "Compensa?" (total ≥ mínimo). Atualiza enquanto digita; grava no servidor. Só para a equipe: não vai no
   e-mail.
9. **Acesso só de administração e desenvolvimento**: a migration tira `proposals.manage` de todo papel que não seja
   `developer`, `super_admin` ou `client_admin` (o módulo antigo dava ao Gerente) e garante nesses três. Exceção por
   usuário continua possível em Permissões. A reversão devolve ao Gerente.
10. Auditoria: cadastrar (`proposals/create`), conta (`proposals/quote`), anexar (`proposal_responses/attach`) e
    enviar (`proposal_responses/send`), além da situação (ADR 0644).

## Consequências
- O Studio deixa de ter formulários de proposta; quem usava o Studio passa a usar o ERP (o botão leva direto).
- A aparência da proposta é responsabilidade do arquivo do Canva; o sistema não muda o conteúdo do PDF.

## Testes
- Integração `ProposalComposerIntegrationTest`: validação e LGPD, cadastro sem e-mail, anexar um PDF (sem mudar a
  situação), anexar dois e enviar num e-mail só (anexos com nome por tipo, mensagem, Proposta enviada), enviar de
  novo sem desfazer Fechada, versão de outra proposta recusada, arquivo que não é PDF e arquivo infectado recusados,
  auditoria.
- Unidade `ErpProposalScreensTest`: rotas antes de `/{id}`, CSRF nas quatro ações, upload só do formulário, conferência
  do PDF, Studio sem gravar/gerar/enviar, API e OpenAPI.
- `tests/erp/render-smoke.php`: lista com "Nova proposta", detalhe com os dois campos de PDF, só anexar/enviar,
  enviar de novo, erro do formulário e a tela de nova proposta (CSRF, escape, aceite).
