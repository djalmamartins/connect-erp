<?php
declare(strict_types=1);

namespace Source\Services\Talk\Service;

/** Mensagens automáticas enviadas ao cliente. Ajuste o tom aqui. */
class Texts
{
    /** Jaqueline: a pessoa só cumprimentou e parou de escrever. */
    public function jackAsk(?string $firstName): string
    {
        return '*Jack:*' . "\n" . ($firstName ? "Oi, {$firstName}!" : 'Oi!') . ' Como posso te ajudar?';
    }

    /** Jaqueline: entendeu o assunto, abriu o chamado e encaminhou. */
    public function jackRouted(?string $firstName, string $protocol, string $sector): string
    {
        return '*Jack:*' . "\n" . ($firstName ? "Certo, {$firstName}!" : 'Certo!')
            . " Abri o chamado *{$protocol}* e já encaminhei para a equipe de *{$sector}*. Já vamos te atender.";
    }

    public function transferred(string $sector): string
    {
        return "Seu atendimento foi encaminhado para o *{$sector}*. Em instantes alguém continua com você.";
    }

    public function closed(string $protocol): string
    {
        return "Atendimento encerrado. Protocolo *{$protocol}*. Se precisar, é só mandar uma nova mensagem.";
    }

    /** Avaliação: vai logo depois do "atendimento encerrado". */
    public function ratingAsk(?string $attendant): string
    {
        return 'Como você avalia ' . ($attendant ? "o atendimento de *{$attendant}*" : 'este atendimento') . '? '
            . "Responda com um número de *1* (ruim) a *5* (ótimo). Se quiser, escreva também um comentário.";
    }

    public function ratingThanks(int $score): string
    {
        return $score >= 4 ? 'Obrigado pela avaliação! Ficamos felizes em ajudar.'
            : 'Obrigado pela avaliação. Vamos usar sua resposta para melhorar o atendimento.';
    }

    /** Jaqueline, quando perguntam se é robô (texto aprovado pelo Djalma em 14/11/2026). */
    public function jackIdentity(): string
    {
        return "*Jack:*\nEu sou a inteligência artificial do time da Connect. Meu nome é Jaqueline, "
            . "mas por aqui o time de TI me chama de Jack. Estou aqui só para agilizar o seu atendimento.";
    }

    /** Jaqueline, quando a conversa sai do que ela sabe fazer. */
    public function jackOutOfScope(string $sector): string
    {
        return "*Jack:*\nIsso está fora do meu escopo de treinamento, então vou te transferir para o setor *{$sector}*. "
            . 'Se tiver mais alguma dúvida, eles podem esclarecer.';
    }
}
