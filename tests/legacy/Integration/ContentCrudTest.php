<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\Faq\Channel;
use Source\Models\Faq\Question;
use Source\Models\Post\Category;
use Source\Models\Post\Page;
use Source\Models\Post\Post;
use Source\Models\Support\SupportArticle;
use Source\Models\Support\SupportCategory;

final class ContentCrudTest extends TestCase
{
    public function testArticleCategoryAndArticleCrud(): void
    {
        $category = new Category();
        $category->title = 'Gestão';
        $category->uri = 'gestao';
        $category->description = 'Conteúdos de gestão';
        self::assertTrue($category->save());

        $article = new Post();
        $article->category = $category->id;
        $article->title = 'Artigo de teste';
        $article->uri = 'artigo-de-teste';
        $article->subtitle = 'Resumo';
        $article->content = '<p>Conteúdo</p>';
        $article->status = 'post';
        $article->post_at = date('Y-m-d H:i:s', time() - 60);
        self::assertTrue($article->save());
        self::assertSame('Artigo de teste', (new Post())->findByUri('artigo-de-teste')?->title);
        self::assertCount(1, (new Post())->findPost()->fetch(true));

        $article->title = 'Artigo atualizado';
        self::assertTrue($article->save());
        self::assertSame('Artigo atualizado', (new Post())->findById((int)$article->id)?->title);
        self::assertTrue($article->destroy());
        self::assertNull((new Post())->findById((int)$article->id));
    }

    public function testPageCrudAndPublishedQuery(): void
    {
        $page = new Page();
        $page->title = 'Institucional';
        $page->uri = 'institucional';
        $page->content = '<p>Conteúdo institucional</p>';
        $page->status = 'post';
        $page->post_at = date('Y-m-d H:i:s', time() - 60);

        self::assertTrue($page->save());
        self::assertSame('Institucional', (new Page())->findByUri('institucional')?->title);
        self::assertCount(1, (new Page())->findPage()->fetch(true));
    }

    public function testFaqChannelAndQuestionCrud(): void
    {
        $channel = new Channel();
        $channel->channel = 'Artigos';
        $channel->description = 'Ajuda sobre artigos';
        self::assertTrue($channel->save());

        $question = new Question();
        $question->channel_id = $channel->id;
        $question->question = 'Como publicar?';
        $question->response = 'Abra o editor e publique.';
        $question->support_link = '/suporte/artigos/como-publicar';
        self::assertTrue($question->save());
        self::assertCount(1, $channel->questions()->fetch(true));
    }

    public function testSupportCategoryAndPublishedArticleCrud(): void
    {
        $category = new SupportCategory();
        $category->title = 'Comunicação';
        $category->uri = 'comunicacao';
        $category->icon = 'message-circle';
        $category->status = 'published';
        self::assertTrue($category->save());

        $article = new SupportArticle();
        $article->category_id = $category->id;
        $article->title = 'Cadastrar comunicação';
        $article->uri = 'cadastrar-comunicacao';
        $article->summary = 'Tutorial completo';
        $article->content = '<p>Passo a passo</p>';
        $article->status = 'published';
        $article->published_at = date('Y-m-d H:i:s', time() - 60);
        self::assertTrue($article->save());
        self::assertSame('Cadastrar comunicação', (new SupportArticle())->findByUri('cadastrar-comunicacao', true)?->title);
        self::assertCount(1, $category->articles(true)->fetch(true));
    }
}
