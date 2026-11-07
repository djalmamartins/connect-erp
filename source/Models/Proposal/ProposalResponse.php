<?php

namespace Source\Models\Proposal;

use Source\Core\Model;

final class ProposalResponse extends Model
{
    public function __construct()
    {
        parent::__construct('proposal_responses', ['id'], ['proposal_id', 'template_type', 'subject', 'introduction', 'scope', 'commercial_terms', 'valid_until', 'status']);
    }
}
