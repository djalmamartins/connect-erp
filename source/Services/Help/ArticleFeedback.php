<?php

declare(strict_types=1);

namespace Source\Services\Help;

use PDO;
use Source\Core\Connect;

/**
 * Visualizações e "Este artigo ajudou?" dos artigos da Central de Ajuda. Antes o mesmo SQL estava copiado no
 * site (Web::supportArticle/supportVote) e na Central (Support::article/vote); agora as duas telas usam este
 * serviço (ADR 0612, parte 2).
 *
 * Um voto por visitante e artigo (hash de artigo + IP + navegador, sem guardar o IP): votar de novo igual não
 * muda nada; votar diferente troca o voto e corrige os dois contadores, numa transação com FOR UPDATE.
 */
final class ArticleFeedback
{
    public const VOTES = ['yes', 'no'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
    }

    /** Hash do visitante para o voto (regra pura): o IP e o navegador não ficam gravados. */
    public static function visitorHash(int $articleId, ?string $ip, ?string $userAgent): string
    {
        return hash('sha256', $articleId . '|' . ($ip ?? '') . '|' . ($userAgent ?? ''));
    }

    public function countView(int $articleId): void
    {
        $this->pdo->prepare('UPDATE support_articles SET views = views + 1 WHERE id = :id')->execute(['id' => $articleId]);
    }

    /**
     * Registra ou troca o voto. Lança a exceção do banco depois de desfazer a transação.
     *
     * @param 'yes'|'no' $vote
     */
    public function vote(int $articleId, string $vote, string $visitorHash): void
    {
        if (!in_array($vote, self::VOTES, true)) {
            throw new \InvalidArgumentException('Voto inválido.');
        }
        $this->pdo->beginTransaction();
        try {
            $find = $this->pdo->prepare('SELECT id, vote FROM support_article_votes WHERE article_id = :article AND visitor_hash = :hash FOR UPDATE');
            $find->execute(['article' => $articleId, 'hash' => $visitorHash]);
            $old = $find->fetch(PDO::FETCH_ASSOC);
            if (!$old) {
                $this->pdo->prepare('INSERT INTO support_article_votes (article_id, vote, visitor_hash) VALUES (:article, :vote, :hash)')
                    ->execute(['article' => $articleId, 'vote' => $vote, 'hash' => $visitorHash]);
                $this->pdo->prepare("UPDATE support_articles SET helpful_{$vote} = helpful_{$vote} + 1 WHERE id = :id")->execute(['id' => $articleId]);
            } elseif ($old['vote'] !== $vote && in_array($old['vote'], self::VOTES, true)) {
                $previous = $old['vote'];
                $this->pdo->prepare('UPDATE support_article_votes SET vote = :vote WHERE id = :id')->execute(['vote' => $vote, 'id' => (int)$old['id']]);
                $this->pdo->prepare("UPDATE support_articles SET helpful_{$previous} = GREATEST(0, helpful_{$previous} - 1), helpful_{$vote} = helpful_{$vote} + 1 WHERE id = :id")
                    ->execute(['id' => $articleId]);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
