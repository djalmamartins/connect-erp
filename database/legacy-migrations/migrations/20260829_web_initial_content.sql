-- Conteúdo público inicial do Moves. Seguro para reaplicação em qualquer ambiente.
-- Registros já cadastrados no Studio são preservados.

INSERT INTO `categories` (`title`, `uri`, `description`, `type`)
SELECT 'Gestão condominial', 'gestao-condominial',
       'Orientações práticas para uma administração transparente, organizada e próxima dos moradores.', 'post'
WHERE NOT EXISTS (SELECT 1 FROM `categories` WHERE `uri` = 'gestao-condominial');

INSERT INTO `posts` (`author`, `category`, `title`, `uri`, `subtitle`, `content`, `status`, `post_at`)
SELECT (SELECT MIN(id) FROM `users` WHERE `status` = 'confirmed'), c.id,
       'Como tornar a gestão do condomínio mais transparente',
       'como-tornar-a-gestao-do-condominio-mais-transparente',
       'Comunicação clara, prestação de contas e processos bem definidos fortalecem a confiança entre gestão e moradores.',
       '<h2>Informação acessível gera confiança</h2><p>Uma gestão transparente começa pela organização das informações. Comunicados, documentos, decisões e prestações de contas precisam estar disponíveis nos canais definidos pelo condomínio.</p><h2>Crie uma rotina de comunicação</h2><p>Estabeleça uma frequência para avisos e relatórios. Use linguagem direta, informe prazos e mantenha um histórico das mensagens importantes.</p><h2>Acompanhe e melhore</h2><p>Registre solicitações, responsáveis e prazos. Com processos visíveis, a equipe reduz retrabalho e os moradores acompanham cada demanda com mais segurança.</p>',
       'post', NOW()
FROM `categories` c
WHERE c.uri = 'gestao-condominial'
  AND NOT EXISTS (SELECT 1 FROM `posts` WHERE `uri` = 'como-tornar-a-gestao-do-condominio-mais-transparente');

INSERT INTO `posts` (`author`, `category`, `title`, `uri`, `subtitle`, `content`, `status`, `post_at`)
SELECT (SELECT MIN(id) FROM `users` WHERE `status` = 'confirmed'), c.id,
       'Manutenção preventiva: organização que evita imprevistos',
       'manutencao-preventiva-organizacao-que-evita-imprevistos',
       'Um plano simples de inspeções ajuda a preservar o patrimônio e a planejar melhor os recursos do condomínio.',
       '<h2>Mapeie os itens essenciais</h2><p>Elevadores, bombas, portões, sistemas elétricos, equipamentos de segurança e áreas comuns precisam de inspeções periódicas.</p><h2>Defina responsáveis e datas</h2><p>Registre a última manutenção, o próximo vencimento, o fornecedor responsável e os documentos relacionados. Alertas antecipados evitam decisões de última hora.</p><h2>Use o histórico a seu favor</h2><p>O histórico de serviços facilita orçamentos, comprova cuidados com o patrimônio e apoia decisões mais seguras nas assembleias.</p>',
       'post', NOW()
FROM `categories` c
WHERE c.uri = 'gestao-condominial'
  AND NOT EXISTS (SELECT 1 FROM `posts` WHERE `uri` = 'manutencao-preventiva-organizacao-que-evita-imprevistos');

INSERT INTO `posts` (`author`, `category`, `title`, `uri`, `subtitle`, `content`, `status`, `post_at`)
SELECT (SELECT MIN(id) FROM `users` WHERE `status` = 'confirmed'), c.id,
       'Comunicação eficiente aproxima gestão e moradores',
       'comunicacao-eficiente-aproxima-gestao-e-moradores',
       'Escolher canais, responsáveis e uma linguagem objetiva reduz ruídos e melhora a convivência.',
       '<h2>Centralize os canais</h2><p>Defina onde cada tipo de informação será publicado e oriente os moradores. Isso evita mensagens perdidas e versões diferentes do mesmo comunicado.</p><h2>Seja objetivo</h2><p>Um bom aviso explica o assunto, quem será impactado, quando acontece e qual ação é necessária. Sempre que possível, inclua um contato para dúvidas.</p><h2>Escute e acompanhe</h2><p>Além de informar, mantenha um fluxo para receber solicitações e responder dentro de prazos conhecidos. Comunicação também é acompanhamento.</p>',
       'post', NOW()
FROM `categories` c
WHERE c.uri = 'gestao-condominial'
  AND NOT EXISTS (SELECT 1 FROM `posts` WHERE `uri` = 'comunicacao-eficiente-aproxima-gestao-e-moradores');

INSERT INTO `pages` (`author`, `title`, `uri`, `content`, `status`, `post_at`)
SELECT (SELECT MIN(id) FROM `users` WHERE `status` = 'confirmed'), 'Política de Privacidade', 'politica-de-privacidade',
       '<h2>Compromisso com a privacidade</h2><p>A Connect Condomínios trata dados pessoais somente para prestar seus serviços, atender solicitações e cumprir obrigações legais.</p><h2>Dados e finalidade</h2><p>Os dados informados nos formulários são utilizados para contato e atendimento. Informações de acesso podem ser registradas para segurança e melhoria do serviço.</p><h2>Seus direitos</h2><p>Você pode solicitar informações, correção ou exclusão de dados pelos canais oficiais de atendimento, observadas as obrigações legais de conservação.</p>',
       'post', NOW()
WHERE NOT EXISTS (SELECT 1 FROM `pages` WHERE `uri` = 'politica-de-privacidade');

INSERT INTO `pages` (`author`, `title`, `uri`, `content`, `status`, `post_at`)
SELECT (SELECT MIN(id) FROM `users` WHERE `status` = 'confirmed'), 'Termos de Uso', 'termos-de-uso',
       '<h2>Uso do site</h2><p>Este site apresenta os serviços da Connect Condomínios e oferece canais de contato e suporte. Ao utilizá-lo, você concorda em fornecer informações verdadeiras e respeitar a legislação aplicável.</p><h2>Conteúdo e disponibilidade</h2><p>Os conteúdos têm caráter informativo e podem ser atualizados. Recursos digitais podem passar por manutenção para preservar segurança e qualidade.</p><h2>Atendimento</h2><p>Dúvidas sobre estes termos podem ser encaminhadas pelos canais oficiais publicados no site.</p>',
       'post', NOW()
WHERE NOT EXISTS (SELECT 1 FROM `pages` WHERE `uri` = 'termos-de-uso');

UPDATE `settings`
SET `site_title` = 'Gestão condominial inteligente e transparente'
WHERE `site_title` IS NULL OR TRIM(`site_title`) = '' OR `site_title` = 'Título alterado no teste';
