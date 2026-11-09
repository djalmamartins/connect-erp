# 0111 — Revisão de segurança do acesso de parceiros e do convite de morador

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Revisão do ciclo 39 (ADR 0090)

## Contexto

O ciclo 39 (ADR 0090) criou o papel `erp_partner`, o convite de morador e a matriz do portal. A revisão
pedida no handoff olhou a escalada de papel no `linkUser`, o escopo no `grant`, a corrida no aceite e o
hash provisório do convite. Também olhou o que mais um usuário com `erp.access` consegue ver.

## Achados e decisão

1. **Painel operacional aberto ao parceiro.** `GET /api/v1/dashboard` e o painel da tela inicial exigiam
   só `erp.access`. O parceiro tem `erp.access` e veria as contagens operacionais dos condomínios
   concedidos (manutenções, contratos, seguros, ocorrências, entregas e reservas). O financeiro já saía
   sem `erp.entries.view`. Agora os dois exigem também `erp.units.view`, a mesma regra dos painéis
   (`/api/v1/panels`).
2. **`linkUser` checava o nível fora da transação.** Agora o usuário fica travado (`FOR UPDATE`) durante a
   checagem de nível, papel, acesso e permissão própria, até o commit.
3. **`grant` e `revoke` sem trava contra o desvínculo.** Um `grant` ao mesmo tempo que um `unlinkUser`
   podia deixar acesso a condomínio para quem já não era parceiro. Sem papel, esse acesso não abre nada,
   mas bloquearia o próximo vínculo. Agora os três travam o vínculo do usuário numa transação.
4. **Entrega do convite.** O dispatcher registra `resident.invitation` (destinatário: só o morador do
   payload, ativo, com e-mail, do condomínio do evento). O token é emitido no envio e só para quem ainda
   não tem entrega registrada. Assim, uma retentativa não invalida o link já enviado. Convite vencido,
   revogado ou aceito vira `cancelled`. O link é `/convite/{token}`, no mesmo formato do `/forget/{token}`.

Pontos conferidos, sem mudança:
- não há parâmetro de papel;
- o papel `erp_partner` só tem `erp.access` e `erp.partner.view`, e sem papel o fallback por nível nega;
- o `grant` exige o condomínio no escopo de quem concede;
- o aceite é um `UPDATE` condicional, e só um pedido consome o token;
- o hash provisório é de um valor aleatório que ninguém recebe.

## Consequências e lacunas

- A rota `/convite/{token}` é do fluxo de cadastro do Studio (outra frente) e ainda não existe. Até ela
  existir, não ligar `ERP_MAIL_DELIVERY=production`. No log e na caixa de teste, o link não chega a morador.
- A abertura de chamado do Help Desk (`Erp\Connect\Tickets`) e as telas de erro exigem só `erp.access`: o parceiro pode abrir chamado.
  Isso foi mantido.
- O token vai no corpo do e-mail na `mail_queue`, como o link de recuperação de senha.
- Não há teste automático das corridas (exige duas conexões). Os testes de integração rodam só no Mac.
