# 0301 — Link público da prestação de contas para os moradores, livro anual e prazo do 5º dia útil

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:** ADR 0031 (fechamento mensal), 0054/0055/0059 (fila de avisos), 0098 (verificação pública), 0160
  (antivírus no download), 0174 (anexos e livro caixa CN-007), 0179/0183 (publicação congelada)
- **Numeração:** faixa avulsa 0300–0319 (a 0140–0159 acabou; 0200–0299 é do Avançar). Saiu como 0300 em paralelo com o ADR 0300 dos logs (thread "Corrigir logs de permissão negada") e foi renumerado para 0301 antes da main.

## Contexto

Pedido do Djalma (14/11/2026, áudio): um link de prestação de contas para mandar todo mês aos moradores, com o que foi
pago e recebido no mês (água, luz, internet, serviços), na ordem "pagamento da Copasa, conta da Copasa, nota fiscal";
ao fechar o ano, um livro com tudo e as notas; tudo com a identidade da Connect. Complemento no mesmo dia: o livro
precisa estar pronto e enviado até o 5º dia útil; o sistema deve avisar quando o mês não estiver fechado e mandar o
e-mail a cada morador, só do condomínio dele.

Já existiam: anexos de nota, comprovante e recibo nos lançamentos e o livro caixa em PDF (ADR 0174), o fechamento
mensal (ADR 0031), a publicação congelada mensal e anual (ADR 0179/0183) e a fila de e-mails aos moradores por unidade
(ADR 0054/0055). Faltavam a página para o morador, o agrupamento por categoria e fornecedor, o envio e o prazo.

## Decisão

### Livro agrupado e congelado (`AccountabilityLinks`, tabela `erp_accountability_links`, migration 20261114_0900)

- Fonte: o livro caixa do mês (`CashBook::build`, regime de caixa). Seções Receitas e Despesas → categoria (conta do
  plano, por código) → fornecedor (nome sem diferença de maiúsculas) → cada lançamento com data, histórico, nº do
  documento, valor e os documentos na ordem **comprovante, conta ou fatura, nota fiscal, recibo, outro**.
- Recebimentos ligados a unidade saem **somados** por categoria ("Recebido de N unidades"), sem a unidade, o nome nem
  os anexos (LGPD). Receitas sem unidade (rendimentos etc.) aparecem uma a uma.
- O livro é gravado como JSON canônico (mesma função do ADR 0179) com sha256 e versão. O caminho dos arquivos nunca
  vai no JSON. Conteúdo alterado direto no banco não é mostrado (o link responde 404 e grava aviso no log).
- Um link por (condomínio, tipo `monthly|annual`, competência `AAAA-MM|AAAA`). Token de 32 hexadecimais (128 bits
  aleatórios) no caminho: `/prestacao/{token}`.
  - Gerar de novo com o mesmo conteúdo não muda nada. Conteúdo diferente (documento anexado depois, mês reaberto e
    corrigido) sobe a versão **no mesmo endereço**: quem recebeu o link vê a versão corrigida.
  - Revogar (motivo de 3 a 255) desliga o endereço; gerar de novo cria **outro** token. Nada é apagado.
  - Mês (ou algum mês do ano) reaberto: o link continua mostrando a última versão, com o aviso "em revisão".
- Só com o mês fechado; o anual, com os doze meses fechados (a mensagem diz quais faltam).
- Tipo de anexo novo **`conta`** ("Conta ou fatura", pasta Comprovantes), para a conta da concessionária. Não conta
  como comprovante de pagamento (a lista "sem comprovante" do livro caixa não muda).

### Geração e envio automáticos

- Fechar o mês (tela AP-030, `POST /api/v1/closings/close` e o lote `POST /api/v1/closings/batch`) chama
  `afterClose()`: gera o link do mês e envia; ao fechar dezembro, também o livro do ano. Roda **depois** de gravar o
  fechamento e nunca o desfaz: erro vira mensagem na tela e log.
- Envio: um aviso por unidade ativa na fila do ERP (`accountability.published`, público `notices`: pessoas ativas da
  unidade com e-mail que não recusaram comunicado), só do condomínio do link. O token não vai para a fila: o
  dispatcher monta o endereço na hora e cancela o aviso se o link foi revogado. A mesma versão não é enviada duas
  vezes; uma versão corrigida pode ser reenviada. Fora de produção nada sai para morador (ADR 0055).
- Modelo `container/send/default/v1/pages/accountability-link.php`.

### Prazo do 5º dia útil (`AccountabilityDeadline`, tabela `erp_accountability_alerts`, `tools/accountability-deadline.php`)

- Rotina diária pelo cron. Para o mês anterior de cada condomínio ativo: enviado → nada; fechado e não enviado → gera
  e envia (em nome de quem fechou); aberto e faltando até 2 dias úteis → aviso "vence em breve"; passou do prazo →
  aviso "atrasada". Cada situação é avisada uma vez, no sino do sistema (`notifications`), para quem tem
  `erp.closing.manage` e alcança o condomínio. Link revogado com o mês fechado não é gerado de novo sozinho.
- Dia útil: segunda a sexta, sem os feriados nacionais (inclusive 20/11) e sem Carnaval, Sexta-feira Santa e Corpus
  Christi (sem expediente bancário), pela Páscoa calculada.

### Telas, API e permissões

- Livro caixa (CN-007): quadro "Link para os moradores" com o do mês e o do ano (versão, enviado, visitas, endereço) e
  os botões Gerar/Atualizar, Enviar aos moradores e Desativar. Ver: `erp.entries.view`; agir: `erp.closing.manage`,
  com CSRF.
- API REST: `GET|POST /api/v1/accountability-links`, `POST /api/v1/accountability-links/{id}/send` e
  `POST /api/v1/accountability-links/{id}/revoke` (OpenAPI). Ver: `erp.closing.view` + `erp.entries.view`; escrever:
  também `erp.closing.manage`. Fora do escopo = 404; mês aberto ou link revogado = 409.
- Página pública (`AccountabilityLinkPublic`, em qualquer host, como a verificação do ADR 0098): sem login, identidade
  da Connect (faixa verde-escura, logo dourada embutida, detalhes dourados, Inter), responsiva e com "Imprimir ou
  salvar em PDF" (abre todos os meses do anual antes de imprimir). Sem cache, `no-referrer` (o token não vaza para os
  documentos), `noindex`, sem moldura, CSP `default-src 'none'` com script por nonce. Limite por sessão: 120 páginas e
  300 documentos a cada 10 minutos.
- Documento pelo link: só os ids que estão no livro congelado, do mesmo condomínio, ainda no Disco Virtual e liberados
  pelo antivírus (ADR 0160). PDF e imagens abrem na aba, com CSP `sandbox`; o resto baixa.

## Consequências

- **Testes.** `AccountabilityLinksTest` (unit: agrupamento e ordem dos documentos, soma das unidades sem dado
  pessoal, anual, períodos, token, dias úteis e feriados, situações do prazo, evento e modelo de e-mail, cabeçalhos e
  CSP, rotas, CSRF e permissões) e `AccountabilityLinksIntegrationTest` (MariaDB: mês aberto recusa, fechamento gera e
  envia um aviso por unidade só do condomínio, token fora da fila, ordem comprovante/conta/nota, download só do livro
  congelado, versão 2 no mesmo endereço, reenvio, "em revisão", adulteração, revogação e endereço novo, escopo, livro
  anual com dezembro e a rotina do prazo com Finados).
- **Central de Ajuda.** Artigo do livro caixa com a seção do link e do prazo (print `livro-caixa-link`), tipo novo no
  artigo de lançamentos e aviso no fechamento mensal.
- **Implantação.** Rodar a migration e incluir no cron do servidor `php tools/accountability-deadline.php` (uma vez
  por dia, de manhã) além do `tools/notifications-dispatch.php` que já existe.
- **Riscos e lacunas (PENDENTE).**
  - Feriados estaduais e municipais não entram no 5º dia útil.
  - O link é público para quem o tiver; quem encaminhar o e-mail compartilha a prestação. Tudo o que estiver anexado
    às despesas aparece (o artigo pede para conferir antes de fechar).
  - O livro anual não tem PDF próprio no servidor: sai pela impressão do navegador. O livro caixa mensal em PDF com
    os anexos (ADR 0174) continua igual.
  - O limite de acessos é por sessão; a proteção principal é o token de 128 bits.
