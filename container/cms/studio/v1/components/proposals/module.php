<?php

return [
    'slug' => 'proposals',
    'name' => 'Propostas',
    'description' => 'Recebe e organiza solicitações comerciais enviadas pelo site.',
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
        ['post', '/proposals/{id}', 'Studio:proposal'],
    ],
    'dashboard' => 'dashboard.php',
];
