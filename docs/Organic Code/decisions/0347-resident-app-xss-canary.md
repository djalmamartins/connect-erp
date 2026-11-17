# 0347 — XSS no app web do morador

- Status: Aceito
- Data: 17/11/2026
- Contexto: plano v1.0, §3.7 (segurança antes da produção); continua os ADRs 0344, 0345 e 0346

## Contexto

O app web do morador (`container/app/connect/v1`) monta as telas no servidor com os dados do condomínio:
nome do condomínio e da unidade, moradores, boletos, comunicados, reservas, chamados, encomendas,
visitas, assembleias e documentos. Boa parte desse texto é digitada pela administradora ou por outro
morador, então precisa sair escapada.

## Decisão

Novo `tests/legacy/Integration/ResidentAppXssCanaryIntegrationTest.php`:

- grava o texto `"'></script><i data-xss>x` em toda coluna de texto de 20 tabelas que o app mostra e no
  nome da moradora;
- abre como a moradora as 27 telas de leitura (inclusive a rolagem infinita dos boletos, que devolve
  HTML para o `app.js`);
- falha se a marca `<i data-xss>` sair crua, se uma tela quebrar, ou se uma tela de dados mostrar o texto
  só na moldura (prova de que a linha gravada chegou à tela).

## Resultado

- **CONFIRMADO:** nada sai cru. Nada a corrigir.
- Prova de que o teste pega a falha: tirando o `$this->e()` do assunto em `pages/announcements.php`,
  ele falhou em "comunicados" (arquivo restaurado).
- O app de celular (`apps/morador`, React Native) escapa por padrão e não usa `dangerouslySetInnerHTML`
  nem HTML em WebView (conferido por busca no código).

## Lacunas

- O recibo só abre para conta paga; com a conta de teste ele volta para o boleto e fica fora da
  varredura (o teste exige que só ele volte).
- Telas de escrita (POST) não renderizam texto gravado; as mensagens de volta são do sistema.
