# 0632 — Consultas do Connect Talk em nome do morador (escopo residents.read)

- Status: Aceito
- Data: 21/11/2026
- Relacionados: ADR 0623 (token com escopos), 0624 (dados dos condomínios), 0386 (2ª via com morador + telefone),
  0302 (aplicativo do morador e vínculos), AP-153 (matriz do portal), 0305 (ocorrência é chamado).
- Numeração: faixa avulsa 0600–0799. A proposta falava em 0625, mas 0625–0631 foram usados por outras threads;
  conferidos índice e branches em 21/11.

## Contexto
Etapa 3 da API do ERP para o Connect Talk. No WhatsApp o morador pergunta "chegou encomenda para mim?", "o salão está
livre dia 12?", "minha reserva foi aprovada?", "quando é a assembleia?". O aplicativo do morador já responde tudo isso
pelo `ResidentPortal`, preso ao vínculo (condomínio + unidade) e à matriz do portal. O Talk não tem a sessão do morador,
só o telefone de quem escreve e o id achado por `/talk/people`.

## Decisão
1. **Rotas GET em `/api/v1/talk/residents/{id}`**, todas com o escopo `residents.read` (já existia desde a 0623):
   o próprio vínculo, `/announcements`, `/common-areas`, `/common-areas/{area}/availability?month=`, `/reservations`,
   `/deliveries`, `/visits`, `/assemblies`, `/assemblies/{assembly}` e `/documents` (só a lista).
2. **Telefone obrigatório no cabeçalho `X-Resident-Phone`** (fora da URL, para não ir para log de acesso). Tem de ser o
   do cadastro (8 últimos dígitos, a mesma regra da 2ª via). Morador fora do escopo do usuário de integração, inativo,
   fora do período, sem unidade ativa, de condomínio inativo ou com telefone diferente = 404 sem motivo.
3. **Mesmo vínculo e mesmas regras do app.** `TalkResidents::link` monta o vínculo no formato do `ResidentLinks` e as
   leituras chamam o `ResidentPortal`: o Talk vê o que o morador veria no app, nada além. Função desligada no portal do
   condomínio = 403 `feature_disabled`, como no app. Assembleia ou área de outro condomínio = 404.
4. **Dados mínimos.** O vínculo devolve só o primeiro nome, condomínio, unidade, tipo de vínculo e funções. Boletos ficam
   na rota própria (`/talk/charges`, ADR 0386, só proprietário e responsável financeiro) e "moradores da unidade" fica
   só no app. Documentos: só a lista; o arquivo se baixa no app.
5. **Contra varredura.** 10 erros de morador/telefone por token em 10 minutos = 429 `too_many_requests` (o telefone
   certo continua passando). Toda consulta vai para a auditoria (`erp_residents`, origem talk, ação, achou ou não),
   com o telefone redigido.
6. **Só leitura.** Reservar, avisar visita e abrir ocorrência pelo WhatsApp ficam para quando o Djalma decidir (pergunta
   aberta da proposta); o caminho é o mesmo `RegistryService::createForResident` do app, com escopo de escrita próprio.

## Consequências
- O Talk responde as dúvidas mais comuns sem mandar o morador para o app. Precisa marcar o escopo "Dados do morador
  identificado" no token (tela Integrações). Sem migration.
- Os chamados do morador e as respostas da equipe ficam para a etapa 4 (`/talk/tickets?updated_since=` e mensagens).

## Testes
`tests/legacy/Unit/TalkResidentsRoutesTest.php` (o que volta do vínculo, rotas, OpenAPI) e
`tests/legacy/Integration/TalkResidentsTest.php` (telefone certo, errado, sem cabeçalho, fora do escopo, vínculo
encerrado, função desligada, item de outro condomínio, auditoria, 429 depois de 10 erros, 403 sem o escopo).
