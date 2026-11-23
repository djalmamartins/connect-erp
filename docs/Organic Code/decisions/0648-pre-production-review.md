# 0648 — Revisão geral antes da produção (23/11/2026)

- Status: Aceito
- Data: 23/11/2026
- Pedido: Djalma, 22/11/2026 21:36: "começa pelo site, vê se todos os links funcionam ... confere se os formulários
  funcionam e se eles enviam para onde tem que enviar. E aí em seguida passa para moradores, para o ERP, para o
  [Help Desk], para o Moves Studio ... revisão geral no sistema para a gente colocar em produção."
- Relacionadas: ADR 0396 (instalação limpa), 0361 (update.sh), 0354 (deploy-check), 0604 (LGPD/CDC no site),
  0141 (Central de Ajuda), 0644 (propostas no ERP)
- Número: 0647 está em uso no branch das ilustrações da gestão do site.

## Como foi feita

Cópia do sistema na nuvem com banco vazio (`composer db:install`, como a produção), administrador criado por
`tools/hosting/criar-admin.php` e dados fictícios (`tools/lab-dados-exemplo.php`). Um robô percorreu todas as
páginas a partir da home e da Central de Ajuda (52 + 712 páginas), conferindo destino de cada link, âncoras e
arquivos. Os formulários foram enviados de verdade e conferidos no banco.

## Site público — o que estava errado e foi corrigido

| Problema | Correção |
|---|---|
| Menu "Diferenciais" levava para `/#apcontrole`: o nome do concorrente aparecia no endereço do site | Âncora `#diferenciais` |
| "Histórias de sucesso" (menu e rodapé) levava para uma seção que some quando não há depoimento publicado (ADR 0604) | O link só aparece com depoimento publicado (`site_has_testimonials()`) |
| Cartão "WhatsApp" da home mostrava o número do telefone | Mostra o WhatsApp (ou o telefone, se o WhatsApp estiver vazio) |
| Sem telefone/WhatsApp/e-mail no Studio, a home tinha links `tel:+`, `mailto:` e `wa.me/` vazios | Cartões e botões só com o dado preenchido; WhatsApp cai no telefone |
| Rodapé "Assessoria Jurídica" e "Manutenção Predial" levavam aos cartões Gestão Administrativa e Gestão Operacional (promessa diferente do serviço, CDC) | Rodapé com os mesmos nomes dos cartões |
| Categorias fixas do blog (Finanças, Legislação...) que não existem no Studio voltavam para /artigos | Só aparecem as categorias cadastradas |
| Busca de artigos tirava os acentos ("gestão" virava "gesto") | `str_search` mantém letras com acento; endereço com `rawurlencode` |
| Página da proposta anunciava ao Google o endereço `/proposta` (404) | `/solicite-sua-proposta` |
| Instalação nova (produção): a Central de Ajuda ficava com 2 artigos; os 190 da base só entram com `php tools/help.php sync`, que nenhum passo do deploy rodava | `composer db:install` e `tools/hosting/update.sh` rodam o sync (falha vira aviso) |
| Formulário "Fale com a gente" da Central de Ajuda sem aviso de privacidade (LGPD) | Frase com link para a Política de Privacidade |
| Deploy sem conferir os dados da Connect no Studio (CDC: CNPJ, endereço e contato no site) | `deploy-check` avisa razão social, CNPJ, telefone, e-mail, endereço e cidade vazios |

## Formulários conferidos

| Formulário | Resultado |
|---|---|
| Solicitar proposta (`/solicite-sua-proposta`) | Grava em `proposals`, registra privacidade e marketing em `privacy_consents`, e-mail para quem pediu e para a equipe, aviso no sino com link para Studio (ERP mostra pela ADR 0644) |
| Fale com a gente (`/suporte/mensagem`) | Abre chamado SAC no Help Desk (origem site) e e-mail para a equipe |
| Busca de artigos | Ok depois da correção dos acentos |
| Banner de cookies (`/cookies`) | Grava a escolha (ADR 0604) |

## Login do ERP e do Studio com senha que tem & ou aspas (crítico para a produção)

CONFIRMADO na instalação limpa: o administrador criado por `tools/hosting/criar-admin.php` (senha gerada com `&`,
`=`, `@`) **não entrava no ERP**: "Credenciais inválidas". Os logins do ERP e do Studio e as telas de troca de senha
do site passavam a senha pelo `FILTER_SANITIZE_FULL_SPECIAL_CHARS` (`a&b` virava `a&amp;b`), enquanto o
criar-admin, o convite do morador, o app e a API usam a senha como foi digitada. Também quem trocava a senha pelo
link do e-mail com um desses caracteres não entrava depois no app do morador.

- `sanitize_keep_password()`: limpa o formulário como antes, menos `password` e `password_re`.
- `Auth::attempt()`: se a senha não confere, tenta a forma antiga (codificada) e, se conferir, grava de novo do
  jeito certo. Assim ninguém que já trocou a senha pelo caminho antigo fica de fora.
- Testes de integração em `AuthTest`.

## Morador, ERP, Help Desk e Studio

| Área | Conferido | Resultado |
|---|---|---|
| App web do morador | 600 telas; termos; ocorrência, pedido de CND | Ocorrência vira OCO no Help Desk e o pedido de CND vira chamado "Pedido de CND" (nunca emite sozinho, ADR 0638); sem link quebrado |
| ERP | 1.500 telas com o administrador | Sem erro de PHP nos logs. "Abrir no Studio" e "Responder no Studio" das propostas iam para `erp.<domínio>/studio/proposals` (404): agora passam pela troca de ambiente (`Environments::link`) |
| Help Desk | 500 telas | Sem link quebrado |
| Studio | 1.200 telas | Sem link quebrado |
