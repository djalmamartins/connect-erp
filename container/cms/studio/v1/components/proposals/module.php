<?php

return [
    'slug' => 'proposals',
    'name' => 'Propostas',
    'description' => 'Consulta das propostas; criar, gerar e enviar ficam no ERP (ADR 0653).',
    'version' => '1.1.0',
    'permission' => 'proposals.manage',
    'menu' => [
        'label' => 'Propostas',
        'icon' => 'document-text-outline',
        'position' => 45,
    ],
    'routes' => [
        ['get', '/proposals', 'Studio:proposals'],
        ['get', '/proposals/{id}', 'Studio:proposal'],
        ['post', '/proposals/{id}', 'Studio:proposal'], // só responde "agora é no ERP" (ADR 0653)
    ],
    'dashboard' => 'dashboard.php',
];
