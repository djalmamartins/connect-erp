# 0094 — Passada de qualidade dos ciclos 37 a 42: escopo, tetos e valor vencido

Data: 2026-11-09 · Status: aceito (revisado na 23ª rodada) · ADRs revisados: 0088 a 0093

## Contexto

O docs/process/BACKLOG.md só traz a sequência das fases, sem itens pendentes. Todos os itens restantes
dependem do Djalma: banco real, remessa e CNAB, verificação pública, antivírus. Por isso o ciclo foi uma
passada de qualidade nas rotas novas dos ciclos 37 a 42, procurando três coisas:
- falta de testes negativos de permissão e escopo;
- consultas sem LIMIT;
- dados pessoais sem máscara.

## Achados e correções

1. **Listagem de imobiliárias** (`PartnerAccess::listAgencies`, ADR 0090):
   - antes, carregava os usuários de todas as imobiliárias e as concessões de todos os parceiros, sem
     teto, e só depois filtrava pelo escopo;
   - agora busca só os usuários das imobiliárias listadas (até `MAX_AGENCIES`) e só as concessões dos
     condomínios do escopo de quem consulta, com `LIMIT`.
2. **Desvincular parceiro** (`PartnerAccess::unlinkUser`, e com ele `deactivateAgency`):
   - antes, não conferia o escopo. Um administrador de um condomínio podia tirar o acesso de um parceiro
     a condomínios que ele não vê;
   - agora exige acesso a todos os condomínios concedidos ao parceiro e responde 404 se faltar algum.
     A conferência roda dentro da transação, depois da trava do vínculo (ADR 0111). O ADR 0111 travou
     o vínculo, mas não conferia o escopo. No `deactivateAgency`, o escopo de todos os parceiros é
     conferido antes de desvincular o primeiro, para não deixar a imobiliária pela metade.
3. **Valor vencido na lista de presença** (`Assemblies::attendanceReport`, ADR 0088):
   - antes, `overdue_cents` por unidade aparecia para qualquer um com `erp.assemblies.view`;
   - agora o valor só sai para quem também tem `erp.agreements.view`, e no CSV a coluna fica vazia.
     O direito a voto (`eligible`) continua visível, porque a assembleia precisa dele.
4. **Teto na lista de presença** (`Assemblies::attendance`): `LIMIT` de `MAX_ATTENDANCE` (5000).
5. **Testes negativos:**
   - `NewRoutesPermissionMatrixTest` (unit, varredura estática): toda ação pública dos controllers novos
     passa por autorização. Cada controller exige as suas permissões, e as ações sensíveis exigem a
     permissão extra: convocar, o valor vencido, a consulta do parceiro, convidar e a memória de
     cálculo;
   - `QualityPassIntegrationTest`:
     - todas as escritas e relatórios da assembleia fora do escopo respondem 404, sem mudar nada;
     - o valor vencido fica oculto por padrão;
     - quem só vê um condomínio não vê nem desvincula concessões do outro;
     - a fila de avisos das Integrações conta só o escopo.

## O que não mudou

- **Ata:** continua listando as unidades sem direito a voto. É o registro da assembleia, sem valores e
  sem nomes.
- **Relatório de pagamentos:** mostra o nome do fornecedor, que é o objeto do relatório, com o documento
  mascarado.
- **Sem migration.**
