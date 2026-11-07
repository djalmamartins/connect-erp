INSERT INTO `support_articles` (`category_id`,`author_id`,`title`,`uri`,`summary`,`content`,`status`,`published_at`)
SELECT c.id,1,'Como diagnosticar problemas no Log','como-diagnosticar-problemas-nos-logs-do-sistema','Use os incidentes automáticos do MovesOS para localizar, acompanhar e resolver erros com segurança.',
'<h2>Quem pode acessar</h2><p>O Log é uma área técnica exclusiva do perfil Desenvolvedor.</p><h2>O que é registrado</h2><p>O MovesOS captura automaticamente erros PHP, exceções, falhas do banco, problemas de upload e e-mail, tentativas de autenticação, erros HTTP e requisições lentas. Senhas, tokens, cookies e credenciais são removidos do contexto.</p><ol><li>No Studio, abra <strong>Log</strong>.</li><li>Use os filtros de nível, estado, origem ou pesquise pelo código do incidente.</li><li>Clique no registro para visualizar URL, arquivo, linha, contexto e rastreamento.</li><li>Depois de corrigir e validar a causa, escolha <strong>Marcar resolvido</strong>.</li><li>Use <strong>Reabrir incidente</strong> se o problema voltar.</li></ol><h2>Falha no banco</h2><p>Quando o banco não estiver disponível, o MovesOS preserva uma cópia em <code>storage/logs</code>. Registros resolvidos são mantidos por 90 dias e incidentes abertos por até 180 dias.</p>',
'published',NOW()
FROM support_categories c WHERE c.uri='suporte-e-seguranca'
AND NOT EXISTS (SELECT 1 FROM support_articles WHERE uri='como-diagnosticar-problemas-nos-logs-do-sistema') LIMIT 1;

-- Evita colisão com o prefixo /suporte em versões anteriores do router.
UPDATE support_categories
SET uri = 'ajuda-e-seguranca'
WHERE uri = 'suporte-e-seguranca';

UPDATE `support_articles` SET `content` = CONCAT(`content`, '<h2>Escolher uma imagem já enviada</h2><p>Nos artigos, páginas, destaques, matérias de suporte e configurações da logo, clique em <strong>Escolher da biblioteca</strong>. Pesquise o arquivo, clique na miniatura e salve o conteúdo. Para inserir uma imagem dentro do editor, abra a opção de imagem e escolha <strong>Biblioteca</strong>.</p><h2>Enviar diretamente</h2><p>Na Biblioteca de Mídia, clique em <strong>Enviar imagem</strong>. Ao selecionar o arquivo, o envio começa automaticamente.</p>')
WHERE `uri`='como-usar-a-biblioteca-de-midia' AND `content` NOT LIKE '%Escolher uma imagem já enviada%';

UPDATE `support_articles` SET `content` = CONCAT(`content`, '<h2>Aplicação automática no site</h2><p>Nome, descrição, logo, telefone, WhatsApp, e-mail, endereço, domínio e redes sociais são aplicados ao site público após salvar. A logo pode ser escolhida diretamente na Biblioteca de Mídia.</p>')
WHERE `uri`='como-configurar-o-movesos-e-trocar-temas' AND `content` NOT LIKE '%Aplicação automática no site%';
