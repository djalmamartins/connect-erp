<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Services\Help\ArticleFeedback;

/** Visualizações e votos dos artigos da Central de Ajuda (ADR 0612, parte 2): um voto por visitante, troca corrige os dois contadores. */
final class ArticleFeedbackIntegrationTest extends TestCase
{
    private int $article;

    protected function setUp(): void
    {
        parent::setUp();
        // Tabela do schema base (database/base/schema.sql), fora das migrations que montam o banco de teste.
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS support_article_votes (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, article_id INT UNSIGNED NOT NULL,
            vote ENUM('yes','no') NOT NULL, visitor_hash CHAR(64) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_support_vote (article_id, visitor_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec('DELETE FROM support_article_votes');
        $author = $this->createUser(["level" => 5]);
        $this->pdo->exec("INSERT INTO support_categories (title, uri, icon, status, position) VALUES ('Cobrança', 'cobranca', 'card-outline', 'active', 1)");
        $category = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO support_articles (category_id, author_id, title, uri, summary, content, status, views, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$category, $author, 'Segunda via', 'segunda-via', '', '<p>x</p>', 'published', 3]);
        $this->article = (int)$this->pdo->lastInsertId();
    }

    public function testViewsAndVotes(): void
    {
        $feedback = new ArticleFeedback($this->pdo);
        $feedback->countView($this->article);
        self::assertSame([4, 0, 0], $this->counters());

        $visitor = ArticleFeedback::visitorHash($this->article, '10.0.0.1', 'Navegador');
        $feedback->vote($this->article, 'yes', $visitor);
        $feedback->vote($this->article, 'yes', $visitor);
        self::assertSame([4, 1, 0], $this->counters(), 'Votar igual de novo não conta duas vezes.');

        $feedback->vote($this->article, 'no', $visitor);
        self::assertSame([4, 0, 1], $this->counters(), 'Trocar o voto corrige os dois contadores.');

        $feedback->vote($this->article, 'yes', ArticleFeedback::visitorHash($this->article, '10.0.0.2', 'Navegador'));
        self::assertSame([4, 1, 1], $this->counters());
        self::assertSame(2, (int)$this->pdo->query('SELECT COUNT(*) FROM support_article_votes')->fetchColumn());
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testInvalidVoteTouchesNothing(): void
    {
        try {
            (new ArticleFeedback($this->pdo))->vote($this->article, 'talvez', 'x');
            self::fail('Voto inválido deveria ser recusado.');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame([3, 0, 0], $this->counters());
        self::assertSame(64, strlen(ArticleFeedback::visitorHash(1, null, null)));
    }

    /** @return array{int,int,int} views, helpful_yes, helpful_no */
    private function counters(): array
    {
        $row = $this->pdo->query("SELECT views, helpful_yes, helpful_no FROM support_articles WHERE id = {$this->article}")->fetch(\PDO::FETCH_NUM);
        return array_map('intval', $row);
    }
}
