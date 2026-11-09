# 0090 — Acesso de imobiliárias parceiras e de moradores, com regras conservadoras

Data: 2026-11-09 · Status: proposto para revisão (escopo levado ao Djalma pelo coordenador; "Vamos seguir
trabalhando") · Páginas: AP-148, AP-152, AP-153

## Contexto

As fichas das três páginas eram scaffold. O ADR 0085 deixou a decisão com o Djalma, e o escopo foi
proposto em quatro perguntas: o que a imobiliária vê, convite de morador, matriz do portal e as regras
de segurança. Mudança de permissão é de alto risco: esta implementação só entra com revisão no PR.

## Decisão

- **Fail-closed não muda:** usuário sem papel continua sem acesso (`Access`, ADR 0006). Login, senha e 2FA
  do Studio não são tocados.
- **AP-148, imobiliária parceira (`PartnerAccess`):**
  - cadastro da imobiliária (nome, CNPJ válido e contato) e vínculo de um usuário a uma imobiliária;
  - papel fixo `erp_partner`, de nível 1, só com `erp.access` e `erp.partner.view`. O serviço não recebe
    papel como parâmetro: nunca concede developer, super_admin nem outro papel;
  - só vira parceiro o usuário comum (`users.level` ≤ 1) sem papel, sem acesso a condomínio e sem
    permissão própria. Vincular nunca rebaixa nem promove ninguém;
  - quem concede só concede condomínio do próprio escopo, e só a usuário parceiro;
  - desvincular tira o papel, todo o acesso a condomínios e o vínculo. Inativar a imobiliária desvincula
    todos;
  - a consulta do parceiro mostra unidades e moradores dos condomínios concedidos, com e-mail, telefone
    e documento mascarados. Não mostra financeiro, inadimplência nem documentos.
- **AP-152, convite de morador (`ResidentInvitations`):**
  - convite para morador ativo com e-mail, pela outbox (`resident.invitation`); fora de produção, só em
    log ou caixa de teste;
  - o token tem 32 bytes aleatórios e nasce na entrega (`issueToken`), porque a outbox remove chaves
    `token` (ADR 0054). Só o sha256 fica no banco;
  - validade de 7 dias e uso único: o aceite é um UPDATE condicionado;
  - um convite pendente por morador; teto de 200 por condomínio em 24 horas;
  - a conta é criada pelo fluxo de cadastro do Studio, que chama `lookup` e `accept`.
- **AP-153, matriz do portal (`ResidentPortalPermissions`):**
  - boletos, reservas, ocorrências, comunicados e documentos, por condomínio;
  - linha ausente, função desconhecida ou erro = desligado;
  - o valor aceita só true ou false;
  - cada mudança é auditada com o antes e o depois.
- **Permissões novas:** `erp.access_admin.view` e `erp.access_admin.manage`, só para developer e
  super_admin. Convidar e listar convites exigem também `erp.residents.view`.
- **Auditoria** de cadastro, vínculo, concessão, revogação, desvínculo, convite, aceite, revogação de
  convite e mudança da matriz, sem token e sem e-mail.

## Consequências

- **Para o alinhamento** (docs/handoff-0090-shared.md):
  - as rotas, o OpenAPI e a lista do TestCase;
  - registrar `resident.invitation` em `NotificationOutbox::EVENTS`, com rótulo;
  - o modelo de e-mail no dispatcher, que chama `issueToken`.

  Até lá, convidar responde 409, porque o evento ainda não existe.
- **Ficam com outras frentes:** o fluxo de aceite no /app e o portal do morador, que aplica a matriz.
- **A down migration** tira o papel e o acesso dos parceiros antes de apagar as tabelas.
