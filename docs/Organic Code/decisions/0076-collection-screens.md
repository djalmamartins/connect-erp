# 0076 — Telas de cobrança: multas pendentes, atendimento e cobrança judicial

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Regras: ADR 0080

## Contexto

O ADR 0080 entregou as multas pendentes com a origem, o atendimento de cobrança com a promessa quebrada
e a cobrança judicial só como acompanhamento. As páginas AP-116, AP-117 e AP-039 ainda mostravam a
demonstração, e a multa avulsa (AP-027) não tinha onde escolher a origem.

## Decisão

- **Dados no servidor pelo mesmo serviço da API** (`CollectionScreens`): com banco e com
  `erp.collections.view` (mais `erp.agreements.view` na AP-117 e na AP-039, como na API), a página
  troca a demonstração pela tela própria. Gravar é pela API REST, com CSRF no cabeçalho (erp.js,
  `data-erp-collections`).
- **AP-116 Multas pendentes:** totais (multas, em aberto, vencidas), filtro "com as pagas" e, por
  multa, a unidade, o motivo, a origem (link para a ocorrência ou a reserva, ou "Não registrada"), o
  vencimento e a situação. A multa abre na AP-027.
- **AP-027 Multa avulsa:** campo "Origem (opcional)" com as 200 ocorrências e reservas mais recentes do
  condomínio (`CollectionScreenOptions::fineOrigins`, com o escopo conferido). A lista mostra só as
  ocorrências do condomínio ou da unidade escolhida e as reservas dela. O serviço confere de novo ao
  gravar.
- **AP-117 Atendimento:** totais do dia, fila na ordem do serviço com os selos de promessa quebrada,
  próxima ação vencida e caso judicial, e "Atender" (`?unidade=ID#atender`), que abre o histórico da
  unidade e o registro de contato. A data e o valor prometidos só aparecem com "Promessa de pagamento",
  e a data passa a ser obrigatória. As datas ficam limitadas a 90 dias (promessa) e 180 dias (próxima
  ação). O histórico não se edita nem se apaga.
- **AP-039 Cobrança judicial:** aviso fixo de que é só acompanhamento. A lista vem filtrada pela
  situação (padrão: não encerrados). Para abrir um caso, primeiro se escolhe a unidade com conta
  vencida e sem caso aberto, depois as contas (com o total), o advogado do cadastro, o processo, a
  vara, a data e a nota. O detalhe mostra o valor na abertura e o ainda em aberto, as contas (paga,
  em aberto ou fora da cobrança) e o andamento. O registro de andamento oferece só as transições
  permitidas, e encerrar pede confirmação, porque é definitivo.
- **Endereços (AP-139), correção do smoke:** a tela recebe as unidades já filtradas e os rótulos do
  vínculo (`CommunicationScreens::RELATIONSHIPS`) do controlador, sem chamar cadastro nem regra ao
  renderizar, e a leitura do contexto passou para dentro do `try`.
- **Smoke com `SMOKE_HOST`:** o alternador de ambiente (`/ambiente/{environment}`, ADR 0034) vale em
  qualquer host e entrou nas rotas conhecidas do smoke.

## Fora deste ADR

- Lembrete automático da próxima ação e aviso de promessa quebrada pela outbox.
- Anexos no caso judicial (podem vir do Disco Virtual).

## Consequências

- `ErpCollectionScreensTest` e o smoke de renderização cobrem as três telas e a origem da multa. Os
  dois modos do smoke (com e sem `SMOKE_HOST`) fecham com 0 falhas.
- Nada foi testado contra MariaDB real nesta rodada.
