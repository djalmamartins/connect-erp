# 0312 — Revisão de 15/11, etapa 3: busca automática do CNPJ nos formulários e advogado com CPF ou CNPJ

- Status: Aceito
- Data: 16/11/2026
- Issue: #542 (revisão de 15/11, etapa 3)
- Relacionadas: ADR 0151 (CNPJ automático no cadastro do condomínio), 0311 (etapa 2)

## Contexto

O Djalma pediu que o fornecedor seja preenchido sozinho a partir do CNPJ, e o mesmo em todo formulário com CNPJ; e que o
advogado aceite CPF ou CNPJ. A busca do condomínio (ADR 0151) já consultava a BrasilAPI no navegador, mas só com os nomes
de campo do condomínio.

## Decisão

1. **Mapa por campo**: o campo de documento ganha `cnpj_lookup` na definição do cadastro, com o campo do formulário que
   recebe cada dado (`razao_social`, `nome_fantasia`, `fantasia_ou_razao`, `email`, `phone`). O `registry-field` escreve
   `data-erp-cnpj-lookup` e `data-cnpj-map`; o erp.js usa o mapa e mantém o comportamento antigo sem ele (condomínio).
   Ligado em: Fornecedores (razão social, nome fantasia, e-mail, telefone), Advogados (escritório, e-mail, telefone),
   Proprietários e inquilinos (nome, e-mail, telefone) e no cadastro rápido de pessoa da troca de inquilino/proprietário.
2. **Mesmas regras de antes**: só campos vazios são preenchidos; só o CNPJ sai do navegador (sem cookies nem referrer);
   CNPJ não ativo na Receita gera aviso; o servidor valida tudo de novo ao salvar. Sem consulta, nada trava o cadastro.
3. **Advogado com CPF ou CNPJ** (migration `20261116_0300_lawyer_document`, `document` de 11 para 14 dígitos): tipo
   `document` (CPF ou CNPJ com dígito conferido) e único entre os advogados. CPF não tem consulta pública: é validado e a
   duplicidade é conferida ao salvar.

## Testes

- Render smoke: atributos da busca no formulário de fornecedores, advogados e pessoas, e no cadastro rápido da troca.
- Integração: advogado com CNPJ gravado, CPF/CNPJ inválido e repetido recusados.
