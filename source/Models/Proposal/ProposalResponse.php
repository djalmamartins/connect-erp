<?php

namespace Source\Models\Proposal;

use Source\Core\Model;

final class ProposalResponse extends Model
{
    /** ADR 0653: os PDFs são anexados prontos; apresentação, escopo e condições ficam vazios (colunas antigas do Studio). */
    public function __construct()
    {
        parent::__construct('proposal_responses', ['id'], ['proposal_id', 'template_type', 'subject', 'valid_until', 'status']);
    }
}
