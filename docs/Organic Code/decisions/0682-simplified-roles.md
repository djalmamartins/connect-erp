# 0682 — Papéis simplificados: cinco para a equipe, morador e imobiliária

- Status: Aceito
- Data: 27/11/2026
- Pedido: Djalma, 27/11/2026 17:08 (Brasília): "Nos tipos ... administrador global, aí gerente,
  financeiro, operador e atendente. Só esses. E aí teria o usuário comum, que é o usuário que é morador
  ou inquilino, e aí teria talvez a imobiliária ... e dá as permissões devidas para cada um."

## Contexto

Os papéis vinham do MovesOS (Desenvolvedor, Super administrador, Administrador do cliente, Gerente,
Operador, Usuário) mais a Imobiliária parceira (ADR 0090). As permissões `erp.*` nasceram quase todas
só para Desenvolvedor e Super administrador. O Operador tinha só `erp.access` no ERP, mas entrava no
Studio e editava artigos e mídia: no teste 3.6 ele via quase tudo como demonstração (ADR 0681) e ainda
assim entrava em telas que não deveria.

## Decisão

Migration `20261127_2030_simplified_roles` (com `.down.sql`), idempotente. Mantém os slugs que o código
já usa e muda nomes, níveis e o conjunto de permissões:

| Papel (slug) | Nível | O que faz |
|---|---|---|
| Administrador global (`super_admin`) | 90 | Tudo, em todos os condomínios |
| Gerente (`manager`) | 50 | Todo o ERP (operação e financeiro) dos condomínios ligados, Studio de conteúdo, Help Desk e Talk. Não mexe em usuários, permissões, configurações, honorários, propostas nem dados pessoais (LGPD) |
| Financeiro (`financial`, novo) | 40 | Lançamentos, faturas, boletos, cobrança, acordos, fechamento, importações, plano de contas, tarifas, fornecedores e documentos da unidade; consulta unidades, moradores e leituras |
| Operador (`operator`) | 30 | Cadastros e rotina (unidades, moradores, reservas, encomendas, visitantes, mudanças, leituras, manutenções, ocorrências, comunicados, assembleias, documentos), vistorias, Meu Dia da equipe e chamados. Sem financeiro e sem Studio |
| Atendente (`attendant`, novo) | 20 | Chamados do SAC e Talk; reservas, encomendas, visitantes e ocorrências; consulta unidades, moradores, faturas, boletos e acordos sem alterar |
| Morador (`user`) | 10 | Só o app do morador; proprietário e inquilino se distinguem pelo vínculo com a unidade |
| Imobiliária (`erp_partner`) | 1 | Somente leitura dos condomínios ligados (ADR 0090) |

- O Desenvolvedor (`developer`) continua para o suporte técnico; o Studio já o esconde de quem não é
  desenvolvedor.
- O "Administrador do cliente" (`client_admin`) sai: as contas dele viram Administrador global antes
  (ganham acesso, não perdem) e o Administrador global recebe tudo o que o papel antigo tinha.
- Fora o Administrador global, cada papel vê só os condomínios ligados a ele em Configurações ›
  Usuários do Sistema; "Todos os condomínios" continua sendo exceção individual.
- `users.level` acompanha o papel (10 administrador, 2 equipe, 1 morador e imobiliária), também no
  Studio ao salvar o perfil.
- As exceções individuais (Studio › Usuários › Permissões específicas) continuam valendo por cima do
  papel; a migration não mexe nelas.

## Consequências

- Operadores atuais perdem o Studio (artigos e mídia) e ganham o ERP de rotina: é o pedido.
- Gerentes perdem `proposals.manage` (já era só administração, ADR 0653) e ganham o ERP inteiro.
- A volta (`.down.sql`) recria o Administrador do cliente com o conjunto antigo, mas as contas
  promovidas ficam como Administrador global; quem estava em Financeiro ou Atendente volta para Operador.
- Morador (proprietário × inquilino) e Imobiliária (situação de pagamento) seguem em ADRs próprias.
- Testes: `tests/legacy/Integration/SimplifiedRolesMigrationTest.php`.
