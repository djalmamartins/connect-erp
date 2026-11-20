# 0397 — Layout único dos e-mails na identidade Connect

- Status: Aceito
- Data: 20/11/2026
- Relacionados: ADR 0028, 0056, 0059, 0090, 0142, 0301 (e-mails existentes); decisão de 07/11 (templates no container send) e de 08/11 (padrão Realizze PJ)

## Contexto
Levantamento de 20/11/2026: o sistema envia 21 tipos de e-mail. 15 usavam a moldura `container/send/default/v1/default.php`,
mas ela era cinza, sem logo, em Arial e com botões azuis (um verde). 6 ficavam fora dela, montados no código: avisos do
Help Desk e da agenda (HTML sem estilo), comunicação interna e nova proposta (roxo, "MovesOS") e as duas propostas
enviadas ao cliente (visual próprio). O Djalma pediu um layout padronizado e, pela LGPD/CDC, identificação da empresa,
política de privacidade e link de descadastro nos e-mails.

## Decisão
1. Moldura única (`default.php`): cabeçalho `#0d2e27` com o logo Connect dourado em PNG (`assets/images/logo-connect.png`,
   com texto alternativo; SVG não abre no Gmail/Outlook), filete dourado `#c5a131`, fonte Inter com alternativas, título
   do e-mail no corpo (`heading`, padrão = assunto) e rodapé com nome, CNPJ (`CONNECT_CNPJ` no `.env`), endereço, telefone,
   e-mail de contato, site e política de privacidade (`MailTemplate::defaults()`). Campo vazio não aparece.
2. Partes reutilizáveis em `partials/`: botão verde `#07884c` em tabela (Outlook), endereço por extenso, quadro de
   destaque (valor, vencimento, protocolo, linha digitável) e nota final.
3. Modelo `notice` para os avisos genéricos; Help Desk (4 avisos), agenda do Studio, comunicação interna, propostas
   (3) e `tools/hosting/email-teste.php` passam a usar a moldura. Todo texto é escapado no template.
4. O rodapé tem o espaço `unsubscribe_url` para o link de descadastro; ele aparece quando o envio informa o link
   (fluxo de descadastro com token é a etapa seguinte).
5. Fora desta mudança: alertas técnicos para desenvolvedores (`AppLogger`, `tools/hosting/watchdog.php`) e o job legado
   `service/jobs/sendCertificate.php`.

## Consequências
- Testes em `MailTemplateTest`: identidade e rodapé, nenhum modelo fora da moldura ou com o azul antigo, escape do `notice`.
- O logo é carregado pelo endereço público (`/container/send/default/v1/assets/images/logo-connect.png`); no lab, só aparece
  para quem abre o e-mail numa máquina que enxerga o `connect.lab`.
- Prévias com dados fictícios em `/mnt/project-files/emails/` (antes e depois).
