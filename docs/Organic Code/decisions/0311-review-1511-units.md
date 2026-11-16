# 0311 — Revisão de 15/11, etapa 2: configurações de cobrança da unidade, configuração em massa, CIB e pessoa única por CPF/CNPJ

- Status: Aceito (partes 1 e 2)
- Data: 16/11/2026
- Issue: #541 (revisão de 15/11, etapa 2)
- Relacionadas: ADR 0014 (motor de cadastros), 0030 (condomínio da barra superior), 0140 (cadastro rápido), 0206 (saída de moradores), 0310 (etapa 1)

## Contexto

Na revisão de 15/11 o Djalma pediu que a lista de unidades tivesse a configuração em massa do modelo 1 (dia de vencimento,
isenção de rateio, porte postal, leituras de gás/água/energia, bloqueio de unificação, responsável pela cobrança, endereço
do boleto, destinatário do e-mail e imobiliária), o código CIB na unidade, CPF opcional mas único, e que a pessoa sem
unidade fique inativa. O modelo é referência de requisitos: o layout e o código são do Connect ERP.

## Decisão

1. **Configurações na unidade** (migration `20261116_0030_unit_billing_settings`, reversível): `cib`, `due_day`,
   `apportionment_exempt`, `postage_cents`, `postage_payer`, `reads_gas`, `reads_water`, `reads_power`, `block_bill_merge`,
   `billing_responsible`, `bill_address`, `bill_email_to` e `partner_agency_id` (imobiliária; sem FK, o serviço confere que ela existe e está ativa).
   Os campos aparecem no formulário da unidade (motor de cadastros), com padrões: porte 0, porte por ambos, responsável não
   definido, endereço do prédio e e-mail para o responsável. Campo de seleção com padrão vem marcado; valor vazio volta ao padrão.
2. **Configuração em massa** na lista de unidades (AP-006): com um condomínio escolhido e permissão `erp.units.manage`, cada
   linha tem uma caixa de marcação (e "marcar todas desta página"). O botão "Configurar unidades em massa" abre um modal em
   que cada campo começa em **Manter como está**; só o que mudou vai para `POST /api/v1/units/bulk-settings`
   (`GET` devolve as imobiliárias ativas). O serviço `UnitBulkSettings` valida cada valor pela mesma regra do cadastro,
   trava as unidades (`FOR UPDATE`) e grava tudo ou nada; unidade excluída ou de outro condomínio = 422, condomínio fora do
   escopo = 404. Auditoria: um `update` por unidade alterada, só com os campos que mudaram e a marca `_bulk`.
3. **Isenção de rateio**: a unidade isenta fica fora das faturas geradas para o condomínio todo (`Invoices::prepare`, por
   fração ou valor por unidade). A fração das demais é normalizada, então o total continua o mesmo. INFERIDO: o Djalma não
   detalhou se a isenção vale também para a fatura de valor fixo; ficou valendo para as duas porque a fatura é a do condomínio
   todo. Lançamentos de unidades escolhidas à mão (atalhos) continuam aceitando a unidade isenta.
4. **CIB** (Cadastro Imobiliário Brasileiro): tipo `cib` no validador, 8 caracteres (7 e o dígito), letras maiúsculas e
   números, exibido como `AAAAAAA-D`, único entre as unidades. PENDENTE: o algoritmo do dígito não é público; só o formato é
   validado.
5. **Pessoa única por CPF/CNPJ**: o documento continua opcional. Regra `same_person` em proprietários e inquilinos: o mesmo
   CPF/CNPJ pode aparecer em vários vínculos (proprietário de duas unidades, responsável financeiro), mas sempre com o mesmo
   nome (sem diferença de acento, maiúscula ou espaço). Nome diferente = erro no campo, sem mostrar o nome do outro cadastro.
6. **Vínculo encerrado vira inativo**: com `inactive_after => ends_on`, um vínculo com fim já passado é gravado inativo.
   Assim a pessoa que não tem mais unidade some das listas de ativos. O fim que passa depois de gravado continua com o encerramento em lote
   da ADR 0241 (`POST /api/v1/residents/expire-ended`); os testes dela passaram a gravar o fim no futuro e ajustar a data depois.

7. **Termo de entrada e de saída** (parte 2, migration `20261116_0130_unit_move_terms`): um texto por condomínio e
   tipo, ou o geral da Connect (condomínio vazio, `scope_key` 0 na chave única), editado em
   `/condominios/unidades/termos` (POST com CSRF) ou `GET/PUT /api/v1/unit-move-terms`. Ordem: do condomínio, geral,
   padrão do sistema (texto próprio do Connect ERP). O texto é puro: linha em branco = parágrafo; as palavras-chave do
   modelo 2 (@condominio, @unidade, @bloco, @morador, @documento, @cidade, @uf, @dataCriado, @linhaAssinatura) são
   trocadas na hora de gerar, cada pedaço escapado (sem editor HTML, para não abrir XSS). O termo de cada pessoa sai da
   ficha da pessoa no painel da unidade (`/condominios/unidades/termo`, `GET /api/v1/unit-move-terms/render`), para
   imprimir; não é gravado. O texto geral exige acesso a todos os condomínios; gravar entra na auditoria.

## Fora desta parte (continua na etapa 2)

Busca e cadastro rápido de inquilino/proprietário na unidade (modelos 4/5) e troca de
inquilino/proprietário com datas e o que fazer com as faturas (modelo 6).

## Testes

- `tests/legacy/Integration/UnitBulkSettingsTest.php`: grava só o pedido, auditoria só do que mudou, tirar dia e imobiliária,
  tudo ou nada com unidade de outro condomínio, 404 fora do escopo, valores inválidos, imobiliária inativa, isenta fora da
  fatura com o total mantido, CIB normalizado e único, mesmo CPF com outro nome recusado, vínculo encerrado inativo.
- `tests/legacy/Unit/UnitBulkSettingsRulesTest.php`: regras puras (configurações, CIB, chave do nome).
- Render smoke: modal e marcação na lista; sem permissão ou sem condomínio, nada aparece; edição e impressão do termo.
- `tests/legacy/Integration/UnitMoveTermsTest.php`: condomínio, geral e padrão, escopo do texto geral, troca escapada das palavras-chave e pessoa de outra unidade recusada.
