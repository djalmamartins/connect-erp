# ADR 0397 — Menu novo com Agenda, Portaria e Leituras, guardando o menu atual

- Status: Aceito
- Data: 17/11/2026 (renumerada de 0358 em 20/11/2026)
- Relacionados: ADR 0305 (ocorrência é chamado), ADR 0141 (Central de Ajuda), docs/specs/routes.json, tools/erp-catalog.py

## Contexto

Na auditoria do APControle de 17/11/2026, o Djalma não achou no nosso menu agenda, manutenção, reservas, agenda
pendente, lista de espera, seguros, contratos e mudanças. Todas essas telas existem, mas estavam em
Operacional > Rotina, um grupo de 22 páginas que a sidebar mostra em seções recolhíveis (3 cliques). As telas de
mudança apareciam só como "Agendamentos" e "Configurações", sem a palavra mudança. O menu do concorrente parece mais
fácil porque usa nomes do dia a dia no 1º nível (Agenda, Portaria, Leituras), não por causa do tamanho da letra
(13px lá; 14px e 13px aqui).

Pedido do Djalma: fazer o item 1 da auditoria (o menu novo), mas guardar o atual.

## Decisão

- O menu vira uma camada própria, `Source\Services\Erp\ErpNav`, montada a partir do catálogo. O catálogo continua
  dizendo o endereço, o grupo de permissão e a visão geral de cada página. Nenhuma rota muda: Reservas continua em
  `/operacional/reservas-areas-comuns` e Importação de Leituras em `/importacoes/importacao-de-leituras`.
- Menu **novo** (padrão): sai o grupo Operacional e entram, depois de Cadastros,
  - **Agenda**: Atendimento (Chamados, Ocorrências, Manutenções, Vistorias), Áreas comuns (Reservas, Agendamentos
    pendentes, Lista de espera), Mudanças (Agendamentos, Configurações), Contratos e seguros (Seguros, Contratos);
  - **Portaria**: as 6 telas de portaria;
  - **Leituras**: Relógios, Leituras, Tarifas e Importação de Leituras (saiu de Importações).
  Os outros grupos ficam como estão. Página nova no catálogo entra sozinha no seu grupo nos dois menus.
- Menu **atual** guardado: igual ao catálogo, com o Operacional. Cada pessoa troca no rodapé da sidebar ("Usar o
  menu antigo" / "Usar o menu novo"). O POST `/preferencias/menu` (com CSRF) grava a escolha no cookie `erp_menu`
  por um ano (HttpOnly, SameSite=Lax); só aceita `novo` ou `atual`.
- A migalha segue o menu escolhido: no menu novo, "Início > Agenda > Áreas comuns > Reservas Áreas Comuns", sem link
  para a visão geral antiga. A visão geral `/operacional` continua funcionando por endereço.
- Grupos só de menu (Agenda, Portaria, Leituras) não têm "Visão geral".
- Central de Ajuda: os caminhos `menu:` e os `[[...]]` dos artigos passam para o menu novo, e o rascunho de artigo
  (`HelpScaffold`) monta o caminho pelo menu novo. A permissão continua sendo a do módulo Operacional.

## Consequências

- Os nomes das telas não mudaram (itens 2 a 4 da auditoria não foram aprovados ainda): "Agendamentos" e
  "Configurações" ficam dentro da seção Mudanças. Multas Pendentes continua em Cobrança.
- Os prints da Central de Ajuda ainda mostram o menu antigo até rodar `tools/help/prints.sh` no lab.
- `tools/erp-catalog.py` não foi alterado: o catálogo versionado tem páginas acrescentadas à mão (CN-006, CN-007) que o
  gerador não conhece, e rodá-lo agora apagaria essas páginas. Fica como pendência à parte.
- Teste: `tests/legacy/Unit/ErpNavTest.php` (toda página aparece uma vez em cada menu, endereços iguais, menu atual
  igual ao catálogo, cookie inválido cai no novo).
