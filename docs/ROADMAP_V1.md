# Connect ERP: caminho até a v1.0 em produção

Análise de 16/11/2026, feita sobre a `main` em `13480e7` (PR #581), as issues abertas, `docs/decisions/README.md`,
`docs/banking/`, `docs/process/PAGINAS-EXEMPLO.md`, `docs/migration/PENDENCIAS.md` e os scripts de `tools/` e `service/`.
É um plano, não muda código. As datas são estimativas e dependem das respostas marcadas com **[Djalma]**.

Lembrete de escopo (ADR-002): uma administradora (Connect) e vários condomínios. Nada de SaaS, planos ou `tenant_id`.

## 1. Resumo

O sistema está funcionalmente quase completo para uma v1.0: cadastros, financeiro, cobrança, fechamento, prestação de contas,
relatórios, operacional, Help Desk, app web do morador e app React Native existem e estão ligados à API REST. Só uma página do ERP
ainda está em demonstração (AP-031 Pagamentos pelo banco). **O que falta para produção é quase todo fora do código de telas:**
hospedagem Cloud Startup configurada, backup fora do provedor, monitoramento, LGPD, homologação dos bancos com webhook e um condomínio piloto rodando
um mês inteiro.

**Estimativa:**

| Marco | Data provável | Condição |
| --- | --- | --- |
| Servidor de produção no ar (sem dados reais) | 04/12/2026 | Cloud Startup contratado e domínio definido até 25/11 |
| v1.0 piloto: 1 condomínio real emitindo boletos | 14/12/2026 | Inter aprovado em produção |
| v1.0 oficial: piloto fechou um mês completo | 15/01/2027 | fechamento de dezembro e prestação de contas conferidos |

Se o servidor ou a aprovação do Inter atrasarem, tudo anda junto: cada semana de atraso nessas duas decisões empurra a v1.0 uma semana.

## 2. O que já está pronto (base para a v1.0)

- **ERP:** ~175 páginas; todas com API, exceto AP-031. Revisão de 15/11 (6 etapas, ADR 0310–0315) na `main`.
- **API REST** `/api/v1` com OpenAPI (`docs/api/`), sessão + CSRF, token Bearer para o app nativo (ADR 0303).
- **Segurança:** 2FA obrigatório para equipe, login com limite de tentativas (ADR 0023), Access fail-closed, isolamento por
  `condominium_id`, revisão da API financeira (ADR 0159), auditoria `system_audit_logs`, modais sem `alert/confirm`.
- **Bancos:** Inter no sandbox (emitir, consultar, cancelar, simular pagamento, PDF, extrato; baixa de hora em hora sem webhook).
  Cora no stage com o mesmo conjunto (ADR 0322), aguardando credencial de teste.
- **Moradores:** app web (ADR 0302) e React Native (ADR 0303), ocorrência = chamado (ADR 0305), 2FA opcional (ADR 0304).
- **Central de Ajuda** com artigo por página (ADR 0141) e "Sobre esta tela" (ADR 0309).
- **NFS-e etapa 1:** honorários e pedido de nota, chave `nfse.enabled` desligada (ADR 0402).
- **Ferramentas:** `tools/backup-database.sh`, `tools/backup-files.sh`, `service/commands/deploy-check.php`,
  `service/jobs/scheduler.sh`, `tools/testar-main.sh`, CI (`quality.yml`, `app-morador.yml`).

## 3. Tudo o que falta, por área

### 3.1 Código e telas
| Item | Situação | Para a v1.0? |
| --- | --- | --- |
| AP-031 Pagamentos (Bancos) | sem backend; depende de pagamento por API no banco | **não** (v1.1; pagar fornecedor pelo internet banking no início) |
| Pendências do modelo 8 (Nova Receita): conta relacionada, tipo da fatura, anexos no envio | aberto desde a etapa 4 de 15/11 | sim, pequeno |
| Prestação de contas: colunas Sobras de rateio e Fundo de reserva, juntar anexos PDF | aberto desde a etapa 5 de 15/11 | sim |
| Lista de revisão do Djalma prometida para depois de 19/11 | ainda não chegou | sim |
| **Connect Talk dentro do ERP** (Djalma, 16/11/2026): trazer a central de WhatsApp (repositório `djalmamartins/talk`, hoje em hospedagem separada) para dentro do Connect ERP, no mesmo login, permissões, condomínios e Help Desk | a dimensionar numa frente própria; o PR #538 (API entre dois sistemas, ADR 0308) deve ser revisto ou fechado | a decidir |
| NFS-e etapas 2 e 3 (certificado A1, envio à API Nacional, nota do condomínio) | dependem de dados fiscais e da norma de dez/2026 | **não** (emitir manualmente no início) |
| Itaú e CNAB 240 genérico | não iniciados | **não** (v1.1) |
| `docs/specs/pages/ap-*.md` com campos PENDENTE | documentação desatualizada | não bloqueia |

### 3.2 Issues antigas abertas (provavelmente já resolvidas)
`#1` (commits só no Mac), `#2` (Fase 1 integração/smoke), `#3` (studio.min.css), `#10` (frontend dourado; o ERP mudou para o
visual verde, 08/11) e `#11` (links `/login`). O trabalho dessas issues foi feito ou substituído em PRs posteriores. Conferir cada
uma em 10 minutos e fechar. `#46` (decisões antes do banco real) fica aberta até a produção do Inter. `#400` vira o PR #538.
`docs/migration/PENDENCIAS.md` é de 07/11 e também está desatualizado (o item 9 já não vale).

### 3.3 Bancos
| Item | Situação |
| --- | --- |
| Inter sandbox: pagador PJ, multa/mora no PDF, erro de validação como Recusado, extrato com permissão | faltam 4 itens do checklist em `docs/banking/INTER.md` |
| Inter: webhook (baixa chega sozinha, reenvio não duplica, segredo errado = 404) | **precisa de endereço público** |
| Inter produção: certificado do Internet Banking, conta real, primeiro boleto de valor baixo | precisa de aprovação **[Djalma]** |
| Cora stage: checklist inteiro (13 itens) | aguarda credencial de teste **[Djalma]** |
| Cora produção | depois do stage; pode ficar para v1.1 se o piloto usar Inter |
| Proxy do webhook: `ssl_verify_client` com a CA do Inter, IPs do banco, corpo até 256 KB | configurar no servidor |

### 3.4 Servidor e implantação: Hostinger Cloud Startup (decisão do Djalma, 16/11/2026)
Hoje não existe nada de produção. O alvo é a hospedagem **Cloud Startup** (Hostinger, painel hPanel), não uma VPS. Ela traz PHP
selecionável, MariaDB, SSH, Composer, Git, cron, SSL grátis, e-mail e backup diário do próprio provedor. Ela **não** tem Docker,
root, processo rodando o tempo todo nem configuração própria do servidor web (é LiteSpeed com `.htaccess`). Conferir no hPanel
antes da etapa 4: versão do PHP (≥ 8.2), extensões `intl`, `pdo_mysql`, `openssl`, `fileinfo`, `mbstring`, versão do MariaDB e
os limites de cron.

O que muda em relação ao laboratório (que roda com `compose.yaml`):
- **Sem Docker:** o código vai por Git/SSH; `composer install --no-dev` e `composer db:migrate` rodam pelo SSH. Banco criado no hPanel.
- **Domínio: `connectcondominios.com.br`** (Djalma, 16/11/2026). Um subdomínio por sistema, espelhando o laboratório:

  | Laboratório | Produção (proposta) | O que roda |
  | --- | --- | --- |
  | `www.connect.lab` | `www.connectcondominios.com.br` (e o domínio sem www) | site e Central de Ajuda (`/suporte`) |
  | `erp.connect.lab` | `erp.connectcondominios.com.br` | ERP, API `/api/v1` e webhook dos bancos |
  | `studio.connect.lab` | `studio.connectcondominios.com.br` | Studio e Help Desk |
  | `app.connect.lab` | `app.connectcondominios.com.br` | app web do morador (e a API que o app nativo chama) |

  Cada subdomínio com a pasta pública apontando para o front controller certo e `.htaccess` para as rotas. O DNS do domínio
  precisa apontar para a hospedagem (ou ser gerenciado por ela). E-mail sai com SPF, DKIM e DMARC desse domínio.
- **Agendador:** o `service/jobs/scheduler.sh` é um laço infinito e não pode rodar ali. Trocar por linhas de cron que chamam os
  mesmos scripts: fila de avisos a cada minuto, `tools/bank-charges-sync.php` de hora em hora, avisos do checklist e lembretes de
  cobrança às 07:00. Os scripts já são independentes; falta só o roteiro das linhas de cron.
- **Backup:** `tools/backup-database.sh` usa `docker compose exec`; precisa de uma versão com `mariadb-dump` direto (ver 3.5).
- **Webhook do banco:** sem proxy próprio não dá para exigir o certificado do Inter (`ssl_verify_client`). Continua protegido pelo
  segredo do endereço e pela consulta de cada título no banco antes de dar baixa (`BankWebhook.php`); a lista de IPs do banco vai no
  `.htaccess`. A consulta de hora em hora segue como garantia se o webhook falhar.
- **Antivírus de upload** (ClamAV, ADR 0098) não existe nessa hospedagem: fica desligado, com os limites de tipo e tamanho.
- **Saída com certificado (mTLS) para o Inter e a Cora** usa o cURL do PHP e deve funcionar; testar logo no começo da etapa 4.
- `APP_ENV=production`, `ERP_DEMO_DATA=false`, `.env` fora da pasta pública, sem rotas de laboratório.
- Segredos só no `.env` do servidor: `CONNECT_BANK_VAULT_KEY` nova, senhas novas do banco. **Rotacionar toda credencial histórica**
  (`PENDENCIAS.md` item 7).
- Roteiro de implantação e de reversão: `deploy-check.php`, migrations, smoke HTTP, volta para a versão anterior.
- E-mail: o e-mail da própria hospedagem ou um SMTP transacional, com SPF, DKIM e DMARC no domínio; ligar `ERP_MAIL_DELIVERY` e a `mail_queue`.
- Recursos do plano (informados pelo Djalma em 16/11/2026): até 4 núcleos, 4 GB de RAM, 100 GB de disco, 2 milhões de inodes,
  banda ilimitada. Suficiente para o piloto e os primeiros condomínios. Cuidados: não subir `node_modules` nem `apps/` (inodes),
  não guardar backups no mesmo disco, acompanhar o espaço dos documentos e fotos; o `memory_limit` do PHP por processo pode apertar
  nos PDFs grandes e nas importações, medir no piloto.

### 3.5 Backup e recuperação
- O backup diário da Hostinger ajuda, mas fica no mesmo provedor. Falta: um backup nosso por cron (`mariadb-dump` + arquivos,
  incluindo `storage/private/documents`), **cifrado**, copiado para fora da Hostinger, guardando 30 dias + 12 meses.
- **Destino fora da Hostinger: o OneDrive de 1 TB do Djalma** (16/11/2026). Envio com `rclone` (um binário só, roda pelo SSH e cron
  da hospedagem) com cifragem do próprio rclone (`crypt`): a Microsoft só vê arquivos embaralhados. O Djalma autoriza o rclone na
  conta Microsoft uma vez; a senha da cifragem fica no `.env` do servidor e numa cópia guardada por ele, fora do Git.
- **PDFs:** quase todos já são gerados na hora do pedido e não ocupam disco (boletos vêm do banco, listas, prestação de contas,
  carta de cobrança, livro-caixa, documentos da unidade). Ficam gravados só o relatório de vistoria (anexo do e-mail), as propostas
  do Studio e um cache de 24 h do PDF público da prestação de contas. O relatório de vistoria pode passar a ser gerado só para o envio
  (tarefa pequena; **feito em 16/11, ADR 0335**: o PDF é apagado no dia seguinte ao envio). O que ocupa espaço de verdade são os arquivos enviados (notas, comprovantes, fotos, anexos), que não dá para gerar
  de novo e vão para o backup.
- **Documentos dos condomínios no OneDrive** (pedido do Djalma, 16/11/2026): os arquivos do Disco Virtual (e depois fotos de vistoria,
  anexos e importações) passam a morar numa pasta do OneDrive, uma subpasta por condomínio, e o ERP lê de lá pela API da Microsoft
  (Microsoft Graph). Hoje o caminho é fixo em poucos serviços (`VirtualDisk`, `PhotoStorage`, `Imports`, `ResidentProfile`), então a
  mudança é um "armazenamento" com duas opções (disco local ou OneDrive) mais a importação dos arquivos que o Djalma já tem lá.
  Cuidados: abrir um arquivo depende da Microsoft responder (1 a 2 s); se a autorização expirar, o ERP continua, só os arquivos
  ficam indisponíveis até autorizar de novo; o OneDrive passa a ser a cópia principal, então o backup desses arquivos precisa de um
  segundo lugar (ou aceitar a lixeira e o histórico de versões do OneDrive, 30 dias); LGPD: a Microsoft entra como suboperadora.
  Tamanho estimado: ~1 semana, junto da etapa 5, para o piloto já começar com os documentos no OneDrive.
  Conta: **OneDrive empresarial da Connect** (Microsoft 365 Business, conta no domínio `connectcondominios.com.br`), não o pessoal
  do Djalma: os dados ficam em nome da empresa, o acesso do sistema é por aplicativo registrado (não depende da senha de uma pessoa)
  e o contrato de tratamento de dados da Microsoft cobre a LGPD. O backup cifrado pode ir para a mesma conta.
- **Testar a restauração** num servidor vazio e anotar o tempo. Sem isso o backup não conta.
  **Feito em 16/11 (ADR 0334):** `tools/hosting/restore-check.sh` restaura num banco de teste e compara com o real; falta
  rodar na hospedagem com o backup do OneDrive (depende do plano e da conta Microsoft).

### 3.6 Monitoramento
- Verificação externa de disponibilidade (`/api/v1/health`) com aviso por e-mail/WhatsApp.
- Alerta quando o scheduler parar, a fila de avisos encher (ADR 0280 já mede) ou a consulta dos boletos falhar.
- **Feito em 16/11 (ADR 0333):** sinal de vida de cada grupo do cron, `/api/v1/health` com as rotinas e vigia de hora em hora
  que avisa por e-mail (`ALERT_EMAIL`). Falta o Djalma escolher o e-mail e criar o monitor externo (roteiro em HOSPEDAGEM.md §8).
- Rotação dos logs, disco e uso de memória; aviso de certificado HTTPS e de certificado do banco perto de vencer.
  **Feito em 17/11 (ADR 0336):** logs por 6 meses, disco e certificado HTTPS no vigia. Falta: memória (medir no piloto) e
  certificado do banco.

### 3.7 Segurança
- Revisão final antes de abrir ao público: acesso cruzado entre condomínios, IDOR, upload, XSS, CSRF nas rotas novas desde 11/11.
  **CSRF feito em 17/11 (ADR 0337):** todos os POSTs de página conferidos, nenhum sem CSRF; teste estático cobre as rotas futuras.
  **Pastas internas bloqueadas em 17/11 (ADR 0338):** `.git`, backups locais, `tools`, `docs` e código dos temas davam 200.
  **Anexos dos chamados só com login em 17/11 (ADR 0339):** rota `/api/v1/ticket-attachments/{id}` e endereço direto bloqueado.
  **IDOR nas 27 rotas GET com id em 17/11 (ADR 0340):** nenhum registro de outro condomínio entregue; teste de integração cobre as futuras.
  **IDOR nas 52 ações que alteram e downloads em 17/11 (ADR 0341):** todas recusam o outro condomínio.
  **App do morador em 17/11 (ADR 0342):** 11 rotas, nada da unidade vizinha nem de outro condomínio.
  **Imobiliárias em 17/11 (ADR 0343):** viam e respondiam os chamados do condomínio; corrigido, só os próprios.
  **XSS em 17/11 (ADR 0344):** 270 telas do ERP renderizadas com texto com HTML em todos os campos; nada sai cru.
  **Help Desk e JavaScript do ERP em 17/11 (ADR 0345):** fila e ficha do chamado escapam tudo; `erp.js` usa `esc()` em todo texto da API.
  **Site e Central de Ajuda em 17/11 (ADR 0346):** blog, páginas e matérias saíam no site com o HTML do Studio sem limpeza (um script colado no editor rodava para o visitante); agora o HTML passa por `RichHtml::clean` ao salvar e ao mostrar.
  **App do morador em 17/11 (ADR 0347):** 27 telas do app web renderizadas com texto com HTML em todos os campos; nada sai cru. O app de celular (React Native) não usa `dangerouslySetInnerHTML` nem HTML em WebView.
  Pendente: Content-Security-Policy no site (depois da hospedagem).
- Teste de carga dos limites (`request_limit`) e revisão do HTML dos templates (pendentes do ADR 0159).
  **Feito em 17/11 (ADR 0348):** com 20 e 50 pedidos ao mesmo tempo, o limite por conta deixava passar 9 a 10 tentativas em vez de 6; agora a conta fica travada durante a conferência e passa exatamente 6. Limite por IP: 30 (banco) e 40 (arquivo, sem perder pedidos com 10 processos). `tools/hosting/load-test-limits.php` repete o teste na hospedagem (HOSPEDAGEM.md §9). A revisão do HTML dos templates foi feita nos ADRs 0344 a 0347.
- Antivírus de upload: hoje desligado por padrão (ADR 0098); decidir ligar em produção.
- Contas: só usuários reais, sem conta de teste, todos com 2FA.

### 3.8 LGPD
- Política de privacidade e termos de uso atualizados para ERP e app do morador (o site tem a página `privacy`, mas o texto é antigo).
- Papel de cada um: Connect como operadora dos dados do condomínio (controlador). Contrato/aditivo com os condomínios. **[Djalma]** com advogado.
- Encarregado (DPO) e canal para o titular. **[Djalma]** nomear.
- Atender pedido do titular: exportar dados de uma pessoa e anonimizar quem saiu, respeitando os prazos legais de guarda
  financeira. Hoje não existe essa função; criar uma versão simples (tela na equipe, não autoatendimento).
- Registro das operações de tratamento (planilha) e regra de retenção dos logs.
  **Feito em 17/11 (ADR 0350):** `docs/security/LGPD_REGISTRO_OPERACOES.md` com 16 operações, suboperadores, medidas e prazos; teste falha se surgir tabela com dado pessoal fora dele. Faltam do Djalma/advogado: confirmar bases legais e papéis, e os prazos PENDENTES (portaria, e-mails, Talk, `system_audit_logs`).
  **Feito em 17/11 (ADR 0351):** relatório e anonimização do titular passam a cobrir portaria, visitas previstas, conselho, mudanças, reservas, lista de espera, presença em assembleia e contatos de cobrança (conselho e atas ficam guardados). Falta o Talk.

### 3.9 Dados reais e piloto
- **[Djalma]** escolher o condomínio piloto e mandar os dados reais: unidades, moradores, saldos iniciais, inadimplência, contas fixas.
- Importar pelas importações existentes, conferir saldo inicial e inadimplentes contra o sistema atual.
- Rodar um mês em paralelo: emitir boletos, receber, conciliar extrato, fechar o mês, gerar prestação de contas e carta de cobrança.
- Convidar os moradores do piloto para o app web.

### 3.10 Apps do morador
- App web: sai junto com o servidor (só precisa do domínio). Entra na v1.0.
- App nativo: falta `eas.json`, contas Apple Developer (US$ 99/ano) e Google Play (US$ 25), ícones e textos de loja, política de
  privacidade e a revisão das lojas (1 a 2 semanas). Proposta: **v1.1**, porque o app web já atende.

## 4. Plano em etapas (uma por vez)

| # | Etapa | O que entra | Tamanho | Depende de | Quando |
| --- | --- | --- | --- | --- | --- |
| 0 | Este plano | análise e documento | — | — | 16/11 (feito) |
| 1 | Limpeza | issues #1/#2/#3/#10/#11 fechadas e `PENDENCIAS.md` revisado (16/11); PR #538 segue com a frente do Talk | 1 dia | — | 16/11 (feito) |
| 2 | Revisão do Djalma | lista que ele traz depois de 19/11 + pendências do modelo 8 e da prestação de contas | ~1 semana | a lista | 20–27/11 |
| 3 | Sandbox dos bancos | itens que faltam do Inter; Cora inteiro quando a credencial chegar | 2–3 dias | credencial Cora; sandbox Inter seg–sex 8h–20h | 23–27/11 |
| 4 | Servidor de produção | Cloud Startup: domínios, HTTPS, deploy por Git/SSH, cron no lugar do scheduler, segredos novos, e-mail, roteiro de implantação/reversão | ~1 semana | plano contratado e domínio | 30/11–04/12 |
| 5 | Backup e monitoramento | backup diário cifrado fora do servidor, teste de restauração, alertas | 2–3 dias | conta de armazenamento externo | 01–05/12 |
| 6 | Segurança e LGPD | revisão final, teste de carga, antivírus, exportar/anonimizar titular, textos | ~1 semana | advogado/encarregado para os textos | 01–09/12 |
| 7 | Webhook e produção do banco | webhook no sandbox com o endereço público; credencial de produção do Inter; primeiro boleto real de valor baixo | 3–5 dias + prazo do banco | aprovação do Djalma | 07–11/12 |
| 8 | Piloto | importar o condomínio piloto, emitir boletos de janeiro, convidar moradores | 1 semana | dados reais | 10–14/12 → **v1.0 piloto** |
| 9 | Mês completo | acompanhar recebimentos, fechar dezembro, prestação de contas, corrigir o que aparecer | ~4 semanas (pouco trabalho por dia) | — | 15/12–15/01 → **v1.0 oficial** |
| 10 | v1.1 | demais condomínios, app nas lojas, Cora produção, NFS-e etapas 2–3, AP-031, Itaú/CNAB | — | — | 2027 |

As etapas 4, 5 e 6 podem andar em paralelo depois que o servidor existir, mas pela regra de uso controlado seguimos uma de cada vez,
na ordem 4 → 5 → 6.

## 5. O que depende do Djalma

1. **Cloud Startup** (até 25/11): plano contratado e acesso SSH liberado para o deploy; o DNS de `connectcondominios.com.br` apontando para a hospedagem. É o que mais segura a data.
2. **Credencial de teste da Cora** (passo a passo em `docs/banking/CORA.md`), ou decidir que a v1.0 sai só com o Inter.
3. **Aprovação de produção do Inter**: certificado da integração no Internet Banking e ok para o primeiro boleto real.
4. **Condomínio piloto** e os dados reais dele.
5. **LGPD**: encarregado, revisão dos textos e do contrato com os condomínios (advogado).
6. **Backup no OneDrive** (autorizar o rclone uma vez e guardar a senha da cifragem) e **SMTP** de produção.
7. **Talk dentro do ERP**: entra na v1.0 ou logo depois (o tamanho sai da análise da frente própria).
8. **Lista de revisão** de depois de 19/11.
9. Confirmar o que fica para a v1.1: app nas lojas, NFS-e automática, AP-031, Itaú/CNAB, Cora produção.

## 6. Riscos
- Prazo do banco para liberar produção é desconhecido; o webhook só testa com o servidor no ar.
- A norma da NFS-e de dez/2026 pode exigir mudança na etapa 1 (ADR 0402).
- Dados reais do piloto costumam trazer casos que o laboratório não tem (saldos antigos, acordos, unidades com histórico).
- Teste de restauração automatizado desde 16/11 (ADR 0334), ainda não rodado na hospedagem. Não há teste de carga até hoje.
- Hospedagem compartilhada em nuvem: sem Docker nem controle do servidor web; se algum limite do plano não servir, o plano B é uma VPS.
- Um servidor só, sem réplica: queda do servidor derruba ERP, app e webhook juntos (aceitável para a v1.0 com backup testado).
