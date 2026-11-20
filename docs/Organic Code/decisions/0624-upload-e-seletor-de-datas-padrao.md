# ADR 0624 — Envio de arquivos e seletor de datas padrão em todo o sistema

- Status: Aceito
- Data: 20/11/2026
- Pedido: Djalma, 20/11/2026 ("acrescentar essas duas melhorias em todo o sistema para upload, para subir um por um, e ... a agenda, para a gente poder marcar dessa forma ... Filtro e etc."), com dois prints de referência: janela de envio com arrastar e soltar, barra por arquivo e "falha no envio" com tentar de novo; calendário de dois meses com início e fim destacados.

## Contexto

CONFIRMADO no repositório (main 718e42b): 25 campos `<input type="file">` e 38 telas com
`<input type="date">` / `datetime-local`, cada um com aparência própria. Só o Disco Virtual tinha área
de arrastar e barra de progresso; o Studio mostrava "Enviando (x%)" no carregador geral; os filtros
De/Até usavam o calendário do navegador, um campo por vez, sem ver o período.

## Decisão

1. Dois componentes compartilhados, sem dependências, em `container/shared/assets/connect-fields/`:
   `connect-upload.js`, `connect-datepicker.js` e `connect-fields.css`. Entram em todos os layouts
   (ERP, Studio/Help Desk, Central de Suporte e widget, app web do morador) pelo helper
   `connect_fields_assets('css'|'js')` com `?v=` da data do arquivo.
2. **Melhoria progressiva**: o `<input>` original continua no formulário com o mesmo `name`, `id`,
   valor e validação, só escondido da vista. Nenhuma rota, controller ou API muda.
3. **Envio**: área tracejada com "Arraste e solte ... ou" + botão, limite de tamanho e tipos visíveis
   (`data-max-bytes`/`data-max-mb`, padrão 20 MB), cartão por arquivo (miniatura, nome, tamanho,
   remover), validação de tamanho e tipo antes de enviar, barra e porcentagem por arquivo,
   "Falha no envio" com "Tentar de novo".
   - Formulário comum com arquivo é enviado em segundo plano (XHR) e a página mostra a resposta do
     servidor como antes (inclusive o aviso do redirecionamento). Download na resposta vira arquivo.
   - `data-cx-upload-queue`: um envio por arquivo, em fila (Biblioteca de mídia do Studio, com
     `data-cx-upload-auto` para começar ao escolher). Os enviados não repetem no "Tentar de novo".
   - Telas com envio próprio (Disco Virtual, anexo do cadastro, anexos da nova receita, formulários
     AJAX do Studio) pintam o progresso pela API `ConnectUpload.progress/done/fail/form*`.
   - Fora do padrão (`data-cx-upload="off"`): só os seletores de **foto de perfil** (Studio e app do
     morador), que já mostram a foto redonda e enviam ao escolher.
   - Versão compacta (`data-cx-upload-size="compact"`) nas respostas de chamado.
4. **Datas**: campo "dd/mm/aaaa" (digitável, com máscara) + botão do calendário; respeita `min`,
   `max`, `required` e `disabled`. Pares viram **período** com calendário de dois meses (um no
   celular): primeiro clique = início, segundo = fim, faixa destacada, atalhos Este mês / Mês
   passado / Últimos 30 dias, Limpar. Pares pelo nome (de/ate, date_from/date_to, from/to,
   inicio/fim, start_date/end_date, starts_on/ends_on, starts_at/ends_at, expected_on/expected_until,
   entry_on/exit_on), pelos ids `-from/-to` da barra de filtros ou por `data-cx-range`.
   `datetime-local` (agenda do Help Desk) ganha o campo de hora ao lado.
5. **Cores de cada sistema** pelas variáveis já existentes (`--erp-brand`, `--studio-primary`,
   `--app-brand`, `--hc-brand`): verde no ERP e app, roxo no Studio, azul no Help Desk.
6. **Acessibilidade**: rótulos ligados ao campo visível, `role="grid"`, `aria-selected`,
   `aria-current="date"`, teclado (setas, PageUp/PageDown, Home/End, Enter, Esc), anúncios em
   `aria-live`, foco do campo escondido repassado ao botão. Sem `alert/confirm/prompt` (ADR 0316/0320).
7. Prints da Central de Ajuda: `tools/help/shots.mjs` marca o componente visível quando o seletor
   aponta o input escondido e preenche data pelo input original.

## Consequências

- Todo campo novo de arquivo ou data já nasce no padrão, sem código extra.
- `type="month"` (competência) continua com o campo do navegador; fica para uma etapa futura.
- PENDENTE: limite real do servidor (`upload_max_filesize` do PHP do Cloud Startup Hosting) deve ser
  ≥ ao limite mostrado; um 413 aparece como "passa do limite do servidor".
- Artigo: `primeiros-passos/enviar-arquivos-e-escolher-datas.md`; teste:
  `tests/legacy/Unit/ConnectFieldsEverywhereTest.php`.
