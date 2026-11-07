<?php

namespace Source\Models\Faq;

use Source\Core\Model;

/**
 * ERP | Class Question
 *
 * @author Djalma Martins
 * @package Source\Models\Faq
 */
class Question extends Model
{
    /**
     * Question constructor.
     */
    public function __construct()
    {
        parent::__construct("faq_questions", ["id"], ["channel_id", "question", "response"]);
    }
}