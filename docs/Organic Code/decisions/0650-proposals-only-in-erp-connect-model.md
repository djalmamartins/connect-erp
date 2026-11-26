# 0650 — Propostas só no ERP (criar, gerar e enviar) e PDF no modelo da Connect

- Status: Aceito
- Data: 26/11/2026
- Relacionados: ADR 0639 (propostas em Studio > Gestão), 0644 (propostas do site no ERP, CN-011), 0604 (aceite no
  pedido de proposta, LGPD), 0391 (PDF com dados pessoais sem cache), 0397 (e-mails na moldura Connect), 0141
  (Central de Ajuda).
- Numeração: faixa avulsa 0600–0799; 0649 já foi usada duas vezes (CondoConta e link do chamado do Talk). Conferidos
  índice e branches em 26/11.

## Contexto
O Djalma pediu (26/11) que todas as funções de proposta fiquem dentro do ERP: criar, gerar e enviar. Até aqui o ERP
(ADR 0644) só listava e acompanhava; gerar e enviar o PDF ficava no Studio, e não havia como cadastrar uma proposta
pedida por telefone ou WhatsApp. Ele também mandou o modelo de proposta que a Connect usa hoje (PDF feito no Canva,
10 páginas: capa, conteúdo, sobre, diferenciais, cronograma, mapa de serviços com o valor, formalização e contato) e
pediu que a geração siga esse modelo.

## Decisão
1. **Tudo no ERP (CN-011, agora "Propostas")**, pelo serviço novo `ProposalComposer`:
   - `GET/POST /comunicacao/propostas/nova`: cadastra a proposta com nome, perfil, e-mail, telefone, condomínio,
     unidades, necessidade e **como chegou** (telefone, WhatsApp, e-mail, indicação, visita, outro). Entra como
     Contatada, com quem cadastrou como responsável, sem e-mail para a pessoa e sem acender o alerta de "nova".
   - `POST /comunicacao/propostas/{id}/resposta`: grava uma versão (`proposal_responses`), gera o PDF e, com
     "Gerar e enviar", põe o e-mail com o PDF anexado na fila; a situação vira Proposta enviada (salvo Fechada,
     Perdida ou Arquivada). "Gerar PDF para conferir" não pergunta; "Gerar e enviar" pede confirmação na modal do
     sistema (botão com `data-erp-no-confirm` pula a pergunta do formulário).
   - `GET /comunicacao/propostas/{id}/pdf/{versão}`: abre o PDF só se a versão for daquela proposta e o arquivo
     estiver em `storage/files/proposals` (sem cache).
2. **Studio só consulta.** A tela do Studio mostra dados, situação, responsável e versões, com "Abrir no ERP". O POST
   antigo responde "agora é no ERP" (com CSRF) e não grava nada. Nenhum dado é apagado ou migrado: é a mesma tabela.
   O aviso do sino e o e-mail interno de proposta nova passam a abrir a proposta no ERP.
3. **PDF no modelo da Connect** (`source/Support/Proposal/templates/connect.php`, Dompdf): 8 folhas A4 com as
   mesmas seções e textos do modelo, cores verde-escura e dourada, logo dourado do site e fonte Montserrat (SIL OFL,
   arquivo de licença junto). O que muda por proposta: condomínio e nome na capa, data de criação e validade,
   apresentação, atividades (uma por linha, com marcação dourada), valor, pagamento e observações no "Mapa de
   serviços", e o nome do condomínio na formalização e na assinatura. Os textos padrão do formulário são os do modelo
   (10 atividades, valor "R$ 0,00/mês" para preencher, validade de 20 dias). Erros de digitação do original
   corrigidos (diferenciais, condomínio, fornecedores).
4. **Sem fotos do modelo.** As fotos do PDF do Djalma vêm do Canva; a licença do Canva não permite extrair a foto
   para usar fora do Canva. Checklist legal (licença de imagem): usamos só formas, pinos e o logo da Connect. Fotos
   próprias ou compradas podem entrar depois, com a licença registrada.
5. **LGPD.** Cadastrar exige marcar que a pessoa pediu a proposta e aceitou o contato (base legal: procedimentos
   preliminares a contrato, a pedido do titular). A equipe nunca marca aceite de novidades. Guarda igual à dos pedidos
   do site (Política de Privacidade). PDF servido com `Cache-Control: private, no-store`.
6. **Migration `20261126_0900_proposals_origin`** (idempotente): `proposals.origin` (padrão `site`) e
   `proposals.created_by`; garante `proposal_responses` e as colunas de anexo do `mail_queue`, que antes só o módulo do
   Studio criava. A reversão tira só as duas colunas novas.
7. **API REST junto**: `POST /api/v1/proposals` (cadastrar) e `POST /api/v1/proposals/{id}/responses` (gerar e,
   com `send: true`, enviar). OpenAPI atualizado.
8. Auditoria: cadastrar (`proposals/create`), gerar (`proposal_responses/generate`) e enviar
   (`proposal_responses/send`), além da situação (ADR 0644).

## Consequências
- O Studio deixa de ter formulários de proposta; quem usava o Studio passa a usar o ERP (o botão leva direto).
- Textos fixos da Connect (sobre, diferenciais, cronograma, formalização, responsável técnico e CRA) ficam no modelo;
  mudar esses textos é mudar o arquivo do modelo (não há tela para isso ainda).
- Prévia sem banco de dados: `php tools/proposals/previa.php saida.pdf [syndic|administrator]`.

## Testes
- Integração `ProposalComposerIntegrationTest`: validação e LGPD, cadastro sem e-mail, gerar (PDF válido, sem mudar
  a situação), enviar (fila com anexo, Proposta enviada), não desfazer Fechada, PDF só pela proposta dona, auditoria.
- Unidade `ErpProposalScreensTest`: rotas antes de `/{id}`, CSRF nas três ações, Studio sem gravar/gerar/enviar,
  textos padrão do modelo, modelo sem fotos e com as fontes e a licença, API e OpenAPI.
- `tests/erp/render-smoke.php`: lista com "Nova proposta", detalhe com gerar/enviar e versões, erro do formulário e a
  tela de nova proposta (CSRF, escape, aceite).
