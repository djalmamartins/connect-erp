# 0647 — Site: ilustrações próprias da Connect em "Especialistas em gestão condominial"

- Status: Aceito
- Data: 23/11/2026
- Relacionados: ADR 0604 (LGPD e CDC no site, que levantou a origem das fotos), 0600 (checklist legal do site).
- Numeração: faixa avulsa 0600–0799; 0643 (login do Talk), 0644 (propostas no ERP), 0645 (sininho da equipe) e
  0646 (e-mails brancos e dourados) são de outras threads. Conferidos índice da main, branches e PRs abertos em 23/11.

## Contexto
A seção "Especialistas em gestão condominial" da home (e da página "Solicite sua proposta") mostrava três fotos
de reunião e manutenção (`gestao-a/b/c.png`). Segundo o Djalma (22/11, ADR 0604) eram imagens geradas no ChatGPT:
uso comercial possível, mas sem autoria da Connect, com cara de banco de imagens e sem dizer nada do serviço.
Em 23/11 ele pediu imagens que sejam da Connect e que o espaço faça mais sentido.

## Decisão
1. **Três ilustrações SVG desenhadas para a Connect**, em `container/web/default/v1/assets/images/gestao/`,
   só com formas geométricas e as cores da marca (verde-escuro `#072617`, dourado `#C5A131`, creme `#F5F2E9`).
   Não usam foto, fonte, ícone ou desenho de terceiros, nem texto dentro do desenho. Pessoas aparecem sem rosto,
   para não serem tomadas como a equipe ou um cliente real.
2. **Cada quadro mostra uma parte do serviço**, com legenda logo abaixo do desenho:
   - prédios ligados ao painel do mês, boleto e selo de conferido — "Prestação de contas todo mês";
   - condôminos em volta da mesa, pauta no quadro e voto de mão levantada — "Assembleias organizadas";
   - chamado no celular e lista da manutenção — "Chamados acompanhados pelo app".
   As legendas só falam do que o sistema já faz (prestação mensal, assembleias, chamados do Help Desk no app),
   sem promessa nova (CDC).
3. **Um só componente** `pages/about-team-art.php`, usado pela home e pela página de proposta. O quadro segue a
   proporção do desenho (`width`/`height` no `<img>`, sem recorte), `loading="lazy"` e texto alternativo que
   descreve a ilustração. No celular (até 420px) os três ficam um embaixo do outro.
4. As três fotos antigas saíram do repositório (nenhuma outra página as usava).

## Consequências
- Nenhuma imagem de terceiros na seção; a licença é da própria Connect (código do repositório).
- Trocar um desenho é editar o SVG (texto), sem programa de imagem. O `site.min.css` foi regerado.
- Sem mudança de banco, rota ou API.
