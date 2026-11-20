# Realizze × Connect ERP — comparação do núcleo (20/11/2026)

Fonte: `realizze.zip` enviado pelo proprietário em 20/11/2026 (raiz PHP 7.1 + subpasta `erp/` PHP 8.1),
comparado com a `main` 0c0f4f4. Nada do zip foi copiado para o repositório; o dump `u494170504_moves.sql`
que vem dentro dele **não** foi aberto além da lista de tabelas (dados pessoais, LGPD).

## Resultado

O núcleo do Connect **é** o Realizze, reescrito para PHP 8.2 e endurecido. Cada parte pedida:

| Parte | Realizze (coffeecode) | Connect (movescode) | Situação |
|---|---|---|---|
| Model (`Core/Model`) | find/fetch/count/save/destroy | mesmos métodos + auditoria de create/update/delete, log de erro de SQL | Connect melhor |
| View (`Core/View`) | league/plates | `movescode/view` (layout, section, push, insert, e()) | equivalente |
| Controller, Session, Connect | singleton PDO, `utf8`, CSRF `md5` | `utf8mb4`, porta, fuso do banco, cookie seguro/httponly/samesite, `use_strict_mode`, CSRF `random_bytes` | Connect melhor |
| Rotas | coffeecode/router | `movescode/router` 1.1: mesmos métodos + middleware, `subdomain`, `domain` | Connect melhor |
| Middleware | não existe na raiz; `erp/` usa o do router 2.x | `Support/Middleware/*` (API, app do morador, Talk) | Connect melhor |
| SEO (`Support/Seo`) | coffeecode/optimizer: title, description, OG, Twitter, Facebook | `movescode/seo` 2.0: tudo isso + JSON-LD Organization/LocalBusiness e sameAs | Connect melhor; JSON-LD passou a ser usado (ADR 0399) |
| Sitemap / robots | não tem | `/sitemap.xml` e `Sitemap:` no robots (ADR 0399) | novo |
| Compress (Minify) | matthiasmullie/minify | `movescode/compress` (CSS com reescrita de url(), JS, gzip, gravação atômica) + `Services/Minify/*` por sistema | equivalente |
| Storage (Upload) | coffeecode/uploader (confia no tipo enviado pelo navegador) | `movescode/storage` + `Support/Upload`: tipo real (finfo), limite por tamanho, EXIF removido, apagar só dentro de storage | Connect melhor |
| Crop (Thumb) | coffeecode/cropper (nome legível no cache) | `movescode/cropper` (WebP, limite de tamanho, cache por hash) | equivalente; imagem corrompida não derruba mais a página (ADR 0399) |
| Paginação (Pager) | coffeecode/paginator | `movescode/pager` com URL amigável `/p/N` | equivalente |
| Helpers (`Boot/Helpers`) | 513 linhas | 980 linhas, todas as funções do Realizze presentes | Connect melhor |
| E-mail | PHPMailer 6 + fila | PHPMailer 6 + fila + layout único (ADR 0397) + DNS de e-mail no deploy-check | Connect melhor |

Única classe sem par: `erp/source/Models/Corporation/AppEnterprises.php` (empresas clientes). Não entra: Connect não é SaaS (ADR-002).

## Testes que comprovam (nuvem, 20/11/2026)

- `composer test:unit`: 1388 testes, 1387 OK; a 1 falha (`StorageCopyTest`) é do ambiente da nuvem
  (subprocesso procura o banco `movesos_test` com outro nome), não do código.
- `composer test:integration`: 697 testes OK (5 min 48 s).

## O que ainda vale melhorar (não bloqueia a v1.0)

1. Nome legível no cache das miniaturas (o Realizze gerava `titulo-600x400-hash.jpg`, melhor para o Google Imagens).
2. Miniatura não percebe troca de arquivo com o mesmo nome (cache por caminho, sem data); hoje o upload sempre gera nome novo, então não acontece na prática.
3. CNPJ da Connect em Configurações para aparecer no rodapé e no JSON-LD (checklist legal; thread da configuração geral).
