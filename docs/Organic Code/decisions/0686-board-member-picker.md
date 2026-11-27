# 0686 — Síndico e conselho escolhidos entre os moradores e os síndicos profissionais, com mandato de 1 ou 2 anos

- Status: Aceito
- Data: 27/11/2026
- Pedido: Djalma, 27/11/2026, nos testes de "adicionar um síndico": ao digitar o nome, dar opções de pessoas do prédio
  (síndico morador); onde fica o histórico, poder cadastrar síndicos profissionais e selecionar qual está; no conselho,
  mostrar a lista de moradores para selecionar. Complemento: campo de mandato de 1 ou 2 anos (máximo 2) que calcula o
  fim pela data inicial, "para não ter erro".

## Contexto

O cadastro Síndico e conselho (AP-014, motor de cadastros, ADR 0014) pedia tudo digitado à mão: nome, CPF/CNPJ,
e-mail, telefone e unidade, mesmo quando a pessoa já estava em Proprietários e inquilinos. Não havia lugar para os
síndicos profissionais, que atendem vários prédios, e o fim do mandato era digitado sem conferência de duração.

## Decisão

- **Síndicos profissionais (CN-013)**: cadastro novo no motor, `erp_professional_managers`, rota
  `/erp/cadastros/sindicos-profissionais`, API `/api/v1/professional-managers`. É da administradora (escopo none, como
  fornecedores e advogados; ADR-002): sem `condominium_id`, o mesmo síndico serve a vários prédios. Usa as permissões
  de Síndico e conselho (`erp.board.view` / `erp.board.manage`), sem permissão nova (outra frente mexe em papéis e
  permissões agora).
- **Vínculos do membro**: `erp_board_members.resident_id` (FK `erp_residents`) e `professional_manager_id` (FK
  `erp_professional_managers`), campos `ref` do motor com `ref_lazy` (novo): as opções não vão no formulário (um
  prédio tem centenas de moradores); só o vinculado aparece, com "Desvincular". O servidor confere como em todo `ref`:
  existe, ativo, mesmo condomínio (ADR 0020). Síndico profissional só para síndico e subsíndico (`only_if` agora aceita
  lista de valores); morador e profissional juntos são recusados (`not_with`, novo).
- **Escolha na tela**: quadro "Escolher a pessoa" na coluna do histórico, com abas Moradores do prédio e Síndicos
  profissionais, busca e "Cadastrar síndico profissional" (outra aba) + "Atualizar a lista"; e sugestões no campo do
  nome a partir de 2 letras (listbox com setas, Enter e Esc). Escolher preenche nome, CPF/CNPJ, e-mail, telefone e
  unidade. Para fiscal, consultivo e suplente, só moradores. Trocar de condomínio desvincula o morador.
- **API**: `GET /api/v1/board-members/candidates?condominium_id=` (erp.access + erp.board.manage; condomínio fora do
  escopo = 404). Moradores só com `erp.residents.view` (LGPD: mesma regra da tela de moradores); sem ela,
  `residents_allowed = false`. Registrada antes do laço dos cadastros, para não cair em `/board-members/{id}`.
- **Mandato**: `term_years` (1 ou 2), obrigatório no formulário, padrão 2 para quem grava pela API sem o campo. A tela
  calcula o fim = início + anos − 1 dia (15/03/2026 + 2 anos → 14/03/2028). O validador recusa fim depois de início +
  anos (`max_years`, novo), aceitando ajuste para antes.
- Migration `20261127_2130_board_member_picker` (idempotente, com `.down.sql`).

## Consequências

- Membros antigos ficam sem vínculo e com `term_years` vazio; ao editar, a duração vem em 2 anos e o limite passa a
  valer. Mandato antigo com mais de 2 anos precisa ter o fim corrigido ao ser editado.
- Morador vinculado que ficar inativo (saiu do prédio) faz a edição do membro pedir "Desvincular" ou outra pessoa.
- Morador ligado a membro ativo não pode ser excluído pelo cadastro (regra do `ref`); inativar continua possível.
- Relatório de dados pessoais (LGPD) ainda não inclui `erp_professional_managers` (igual a fornecedores e advogados);
  fica como lacuna registrada.
