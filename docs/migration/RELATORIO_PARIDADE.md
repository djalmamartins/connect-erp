# Relatório de paridade

## Evidências aprovadas

- PHP lint: código WEB/CMS, núcleo e serviços sem erro de sintaxe.
- PHPUnit: 41 testes, 143 asserções, todos aprovados em banco `_test` isolado.
- Composer: lock instalado, PSR-4 otimizado e sem advisories conhecidos.
- WEB: home, proposta, legais e artigos HTTP 200.
- CMS: login HTTP 200; áreas autenticadas redirecionam anônimo.
- Assets WEB/CMS: CSS HTTP 200.
- Segurança HTTP: `.env`, source, Composer e database HTTP 403.
- API: `/api/v1/health` HTTP 200 e MariaDB `ready`.
- Banco: 53 tabelas e `mariadb-check` integral aprovado.

## Estado

Paridade estrutural e funcional automatizável: aprovada para revisão.
Paridade visual pixel a pixel: pendente, pois a referência externa original não
estava acessível. Login válido, publicação manual, upload real, SMTP e proposta
com PDF requerem credenciais/fluxo humano autorizado. Não se declara paridade
completa enquanto essas verificações estiverem pendentes.
