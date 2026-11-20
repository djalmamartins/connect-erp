# 0391 — Todo download com Cache-Control private

- Status: Aceito
- Data: 18/11/2026
- Contexto: ROADMAP_V1 §3.7 (revisão final de segurança) e continuação dos ADRs 0388 a 0390.

## Contexto
Quase todo download do sistema já saía com `Cache-Control: private, no-store`. Seis não saíam, e sem esse cabeçalho um
proxy ou cache no caminho pode guardar o arquivo e servi-lo a outra pessoa, e o navegador guarda uma cópia em disco:

- PDF da proposta comercial no Studio (nome, e-mail e telefone do interessado);
- planilha de usuários do Studio (nome, e-mail, telefone);
- relatório de vistoria em PDF;
- anexo e livro anual em PDF do link público da prestação de contas;
- planilha de atendimentos do Talk (contato e telefone).

## Decisão
Os seis passam a mandar `Cache-Control: private, no-store`. O teste estático `DownloadCacheHeadersTest` confere cada
`header('Content-Disposition...')` em `source/` e `container/` e pede um `Cache-Control` com `private` por perto, para as
rotas futuras não esquecerem. Ficam de fora só os controladores antigos sem rota de `Erp/V1` (aguardam a decisão de apagar).

## Consequências
- O navegador baixa o arquivo de novo a cada abertura (são arquivos pequenos ou gerados na hora).
- As fotos de vistoria continuam com `private, max-age=3600`: privado, só o navegador de quem viu guarda.
- Testes: `DownloadCacheHeadersTest` (falhava em 6 pontos antes da correção); unidade e integração de prestação de contas,
  vistoria e Talk passando.
