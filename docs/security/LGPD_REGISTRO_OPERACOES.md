# Registro das operações de tratamento de dados pessoais (LGPD, art. 37)

Versão 1, de 17/11/2026, montada a partir do código e do banco do Connect ERP (ADR 0350). Este registro **não substitui a
revisão do advogado**. As bases legais e os prazos marcados como **INFERIDO** são propostas técnicas. Quem decide é a Connect,
com o advogado e o encarregado (DPO), que ainda não foi nomeado (ROADMAP §3.8).

**Papéis (INFERIDO; precisa constar no contrato com cada condomínio):**

- O **condomínio** é o controlador dos dados dos moradores, funcionários, visitantes e fornecedores dele.
- A **Connect** é operadora desses dados, porque administra o condomínio em nome dele.
- A Connect é controladora só dos dados da própria equipe, dos visitantes do site e de quem pede atendimento na Central de Ajuda.

Como manter: toda tabela nova com dado pessoal (nome de pessoa, CPF, e-mail, telefone, placa, IP, foto) precisa aparecer
aqui com o nome entre crases. O teste `PersonalDataRegisterIntegrationTest` lê o banco e falha se faltar alguma.

## 1. Operações

| # | Operação | Titulares | Dados | Tabelas | Finalidade | Base legal (INFERIDO) | Guarda |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | Cadastro de condôminos | proprietários, inquilinos, moradores, responsáveis financeiros | nome, CPF/CNPJ, e-mail, telefone, unidade, endereço de cobrança | `erp_residents`, `erp_units`, `erp_moves` | administrar o condomínio, cobrar a taxa, avisar | art. 7º, V (contrato/convenção) e II (obrigação legal, Código Civil art. 1.336) | enquanto houver vínculo; depois, 5 anos (cobrança, CC art. 206 §5º) e anonimização (ADR 0330) |
| 2 | Cobrança e financeiro | condôminos pagadores | nome, CPF/CNPJ, valores, boletos, Pix, histórico de pagamento | `erp_entries`, `erp_bank_charges`, `erp_collection_contacts` | emitir e baixar boletos, cobrar atraso, prestar contas | art. 7º, V e X (proteção do crédito) | 5 anos após o pagamento (CC art. 206 §5º); 10 anos se houver ação judicial (INFERIDO) |
| 3 | Bancos (Inter, Cora) | condôminos pagadores | nome, CPF/CNPJ, endereço, valor | `erp_bank_charges` (enviado ao banco pela API) | registrar boleto e Pix | art. 7º, V | conforme o banco (suboperador) |
| 4 | Portaria e visitas | visitantes, prestadores, moradores | nome, documento, telefone, placa, hora de entrada e saída | `erp_visitors`, `erp_visit_forecasts`, `erp_access_logs`, `erp_vehicles`, `erp_gatehouses` | segurança do condomínio | art. 7º, IX (legítimo interesse do condomínio) | **PENDENTE**: hoje sem prazo; proposta de 1 ano para entradas e saídas |
| 5 | Animais e veículos | moradores | nome do tutor ou dono, placa, modelo | `erp_pets`, `erp_vehicles` | regras da convenção, vagas | art. 7º, V | enquanto houver vínculo (anonimizado com o morador) |
| 6 | Reservas e espera | moradores | nome, unidade, data | `erp_reservations`, `erp_waitlist` | uso das áreas comuns | art. 7º, V | 2 anos (INFERIDO) |
| 7 | Assembleias e conselho | condôminos, síndico, conselheiros | nome, documento, e-mail, telefone, presença, voto | `erp_assembly_attendance`, `erp_board_members`, `erp_professional_managers` (síndicos profissionais, ADR 0683) | convocar, registrar presença e votos, ata | art. 7º, II (Código Civil e convenção) | permanente (a ata é documento do condomínio) |
| 8 | Funcionários do condomínio | funcionários | nome, documento, e-mail, telefone | `erp_employees` | gestão do condomínio | art. 7º, V e II | 5 anos após o desligamento (INFERIDO; folha fora do sistema) |
| 9 | Fornecedores, advogados, imobiliárias, contatos | pessoas de contato e autônomos | nome, CPF/CNPJ, e-mail, telefone, chave Pix | `erp_suppliers`, `erp_lawyers`, `erp_partner_agencies`, `erp_useful_contacts`, `condominiums` | pagar, contratar, contatar | art. 7º, V | 5 anos após o último pagamento |
| 10 | App do morador | moradores com conta | e-mail, senha (hash), foto, aparelho, 2FA | `users`, `erp_resident_app_profiles`, `erp_resident_app_tokens`, `erp_resident_invitations` | acesso ao portal | art. 7º, V | enquanto a conta existir; foto e perfil apagados ao excluir a conta (ADR 0377) |
| 11 | Chamados e Central de Ajuda | moradores, visitantes do site, equipe | nome, e-mail, telefone, mensagens, anexos | `studio_support_tickets`, `studio_support_ticket_messages`, `studio_support_ticket_attachments` | atender pedidos e ocorrências | art. 7º, V e IX | 5 anos (INFERIDO); anonimização junto com o morador |
| 12 | Vistorias | quem recebe o relatório | e-mail | `inspection_report_deliveries` | enviar o relatório | art. 7º, V | PDF apagado após envio (ADR 0335); registro do envio, PENDENTE |
| 13 | E-mails e avisos | todos que recebem e-mail | nome, e-mail | `mail_queue`, `erp_notification_outbox` | entregar avisos e boletos | art. 7º, V | **PENDENTE**: hoje sem limpeza; proposta de 6 meses |
| 14 | Connect Talk (WhatsApp) | moradores e contatos | nome, telefone, mensagens; 2ª via do boleto (linha digitável e Pix) só ao proprietário ou responsável financeiro com o telefone do cadastro (ADR 0386) | `talk_contacts`, `talk_channels`, `talk_outbox`, `talk_campaign_recipients`, `talk_users`, `talk_login_attempts`, `talk_audit_log` | atendimento e comunicados | art. 7º, V e IX; campanha só com consentimento ou interesse do condomínio | tentativas de login: 1 dia; demais, **PENDENTE** |
| 15 | Equipe da Connect | funcionários da Connect | nome, e-mail, documento, foto, 2FA | `users` | acesso ao sistema | art. 7º, V | enquanto houver vínculo |
| 16 | Segurança e auditoria | todos que usam o sistema | IP, navegador, ação feita | `system_audit_logs`, `app_log`, `auth_environment_handoffs`, `user_password_resets`, `auth_throttle_events` (só hash) | provar quem fez o quê, evitar fraude | art. 7º, II (Marco Civil art. 15: 6 meses) e IX | `app_log` 90 a 180 dias; arquivos de log 6 meses (ADR 0336); limitador 1 dia; troca de ambiente 1 dia; `system_audit_logs` **PENDENTE** (proposta: 5 anos, por ser trilha financeira) |
| 17 | Pedido de proposta no site (ADR 0604) | síndicos, conselheiros, moradores e interessados | nome, e-mail, WhatsApp, condomínio, unidades, função, mensagem, hash do IP e navegador | `proposals` | responder ao pedido e preparar a proposta | art. 7º, V (procedimentos antes do contrato); novidades só com consentimento separado (art. 7º, I) | **PENDENTE**: proposta de 2 anos após o último contato quando não virar contrato |
| 18 | Aceites e consentimentos (ADR 0604) | visitantes do site, quem pede proposta, usuários | finalidade, versão aceita, canal, conta ou e-mail, hash do IP e navegador (nunca o IP) | `privacy_consents` | provar o aceite e o consentimento (art. 8º, § 2º) e respeitar a retirada | art. 7º, II e VI | enquanto o aceite valer e mais 5 anos (INFERIDO); só acrescenta linhas |

## 2. Com quem os dados são compartilhados (suboperadores)

| Quem | O que recebe | Por quê |
| --- | --- | --- |
| Hostinger (hospedagem) | tudo (banco e arquivos) | onde o sistema roda |
| Microsoft (OneDrive da Connect) | backup cifrado; com `DOCUMENT_STORAGE=onedrive`, documentos, fotos de vistoria e fotos dos moradores (ADR 0373/0374) | backup e arquivos do sistema |
| Banco Inter e Cora | nome, CPF/CNPJ, endereço e valor do pagador | registrar boleto e Pix |
| Provedor de e-mail (SMTP) | nome, e-mail, conteúdo do aviso | entregar e-mail |
| WhatsApp (Meta), pelo Talk | telefone e mensagem | atendimento |

## 3. Medidas de segurança (art. 46)

- Acesso por papel e por condomínio, conferido no servidor. Imobiliária vê só o que é dela (ADR 0343).
- 2FA para a equipe; senhas com hash; limite de tentativas por conta e por IP com trava (ADR 0348).
- HTTPS; cookies de sessão seguros; CSRF em todo formulário (ADR 0337); texto escapado nas telas (ADR 0344 a 0347).
- Anexos e documentos só com login (ADR 0339); pastas internas bloqueadas (ADR 0338).
- Backup diário cifrado fora do servidor com teste de restauração (ADR 0334).
- Logs sem senha, token nem documento completo (máscara `AuditMask`, ADR 0097).

## 4. Direitos do titular (art. 18)

- **Acesso:** relatório por CPF/CNPJ ou e-mail, em Configurações > Dados pessoais (ADR 0328).
- **Eliminação ou anonimização** de quem saiu, respeitando a guarda financeira (ADR 0330).
- Desde o ADR 0351 o relatório cobre também a portaria (`erp_access_logs`, `erp_visit_forecasts`, pelo documento), o
  conselho (`erp_board_members`, por documento ou e-mail) e os registros que guardam só o nome na unidade (`erp_moves`,
  reservas, lista de espera, presença em assembleia e contatos de cobrança). A anonimização apaga nome e documento nesses
  registros, menos no conselho e na presença em assembleia, que fazem parte das atas.
- Desde o ADR 0353 o relatório cobre também o Connect Talk (contato, conversas, mensagens e campanhas), achado pelos
  telefones dos cadastros encontrados, só para quem acessa todos os condomínios. A anonimização apaga nome e telefone do
  contato e mantém as mensagens, como registro do atendimento.

## 5. Decisões pendentes (Djalma e advogado)

1. Confirmar papéis (condomínio controlador, Connect operadora) e incluir no contrato com cada condomínio.
2. Nomear o encarregado e publicar o canal do titular.
3. Prazos marcados como PENDENTE: portaria, e-mails, Talk e `system_audit_logs`.
4. Desde 17/11/2026 (ADR 0359) o Talk em uso roda no projeto separado, num Raspberry Pi no escritório: os dados dele
   ficam no Pi, fora do relatório e da anonimização do ERP. Pedido de titular precisa ser atendido também lá (**PENDENTE**
   no projeto `djalmamartins/talk`).
5. Texto da política de privacidade do site e do app (§3.8 do ROADMAP).
