<?php

declare(strict_types=1);

/**
 * DEMONSTRAÇÃO — dados fictícios do dashboard do Connect ERP v1.
 * Nomes, valores e eventos são inventados; não representam condomínios reais.
 */
return [
    'period' => 'Novembro de 2026',
    'kpis' => [
        ['key' => 'condominiums', 'label' => 'Condomínios ativos', 'value' => '24', 'meta' => '1.186 unidades administradas', 'icon' => 'building', 'tone' => 'hero', 'trend' => ['up', '+2 no ano']],
        ['key' => 'received', 'label' => 'Arrecadação do mês', 'value' => 'R$ 1.284.530', 'meta' => '87,4% do previsto', 'icon' => 'wallet', 'tone' => '', 'trend' => ['up', '+3,1%']],
        ['key' => 'delinquency', 'label' => 'Inadimplência', 'value' => '6,8%', 'meta' => 'R$ 187.940 em aberto', 'icon' => 'alert', 'tone' => 'danger', 'trend' => ['down', '−0,6 p.p.']],
        ['key' => 'payables', 'label' => 'A pagar em 7 dias', 'value' => 'R$ 342.115', 'meta' => '58 títulos · 4 vencidos', 'icon' => 'receipt', 'tone' => 'warning', 'trend' => null],
    ],
    'cashflow' => [
        'labels' => ['Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov'],
        'series' => [
            ['name' => 'Receitas', 'values' => [1180, 1215, 1198, 1240, 1262, 1284]],
            ['name' => 'Despesas', 'values' => [1010, 1095, 1032, 1120, 1088, 1131]],
        ],
        'unit' => 'R$ mil',
    ],
    'delinquencyByAge' => [
        ['label' => 'Até 30 dias', 'value' => 46, 'color' => '#07884c'],
        ['label' => '31 a 90 dias', 'value' => 28, 'color' => '#c5a131'],
        ['label' => '91 a 180 dias', 'value' => 15, 'color' => '#d97a2b'],
        ['label' => 'Acima de 180 dias', 'value' => 11, 'color' => '#d92d36'],
    ],
    'alerts' => [
        ['tone' => 'danger', 'title' => '4 contas a pagar vencidas', 'text' => 'Residencial Jardim das Acácias e mais 2 condomínios', 'href' => '/financeiro/contas-a-pagar'],
        ['tone' => 'warning', 'title' => 'Retorno bancário não processado', 'text' => 'Arquivo de 06/11 aguardando conciliação', 'href' => '/cobranca/retornos'],
        ['tone' => 'warning', 'title' => 'Fechamento de outubro pendente', 'text' => '3 condomínios sem fechamento mensal', 'href' => '/financeiro/fechamento-mensal'],
        ['tone' => 'info', 'title' => 'Assembleia em 5 dias', 'text' => 'Edifício Monte Verde · AGO 12/11 às 19h', 'href' => '/comunicacao/assembleia-virtual'],
    ],
    'activities' => [
        ['icon' => 'check', 'title' => 'Boletos de dezembro gerados', 'text' => 'Condomínio Parque das Palmeiras · 212 boletos', 'when' => 'há 12 min'],
        ['icon' => 'file', 'title' => 'Prestação de contas publicada', 'text' => 'Edifício Solar do Atlântico · outubro', 'when' => 'há 1 h'],
        ['icon' => 'users', 'title' => 'Novo morador cadastrado', 'text' => 'Residencial Bela Vista · unidade 304-B', 'when' => 'há 2 h'],
        ['icon' => 'clipboard', 'title' => 'Ocorrência encerrada', 'text' => 'Vazamento na garagem · Condomínio Vila Rica', 'when' => 'há 3 h'],
        ['icon' => 'receipt', 'title' => 'Acordo firmado', 'text' => 'Unidade 1201 · 6 parcelas', 'when' => 'ontem'],
    ],
    'condominiums' => [
        ['name' => 'Residencial Jardim das Acácias', 'city' => 'Campinas/SP', 'units' => 96, 'received' => 92.4, 'delinquency' => 4.1, 'status' => 'Em dia'],
        ['name' => 'Edifício Solar do Atlântico', 'city' => 'Santos/SP', 'units' => 64, 'received' => 88.0, 'delinquency' => 7.9, 'status' => 'Atenção'],
        ['name' => 'Condomínio Parque das Palmeiras', 'city' => 'Campinas/SP', 'units' => 212, 'received' => 90.6, 'delinquency' => 5.2, 'status' => 'Em dia'],
        ['name' => 'Edifício Monte Verde', 'city' => 'Jundiaí/SP', 'units' => 48, 'received' => 79.3, 'delinquency' => 12.4, 'status' => 'Crítico'],
        ['name' => 'Residencial Bela Vista', 'city' => 'Valinhos/SP', 'units' => 120, 'received' => 94.8, 'delinquency' => 3.0, 'status' => 'Em dia'],
        ['name' => 'Condomínio Vila Rica', 'city' => 'Campinas/SP', 'units' => 80, 'received' => 85.1, 'delinquency' => 8.6, 'status' => 'Atenção'],
    ],
    'agenda' => [
        ['day' => '10', 'month' => 'nov', 'title' => 'Vencimento das taxas condominiais', 'text' => '18 condomínios'],
        ['day' => '12', 'month' => 'nov', 'title' => 'AGO Edifício Monte Verde', 'text' => 'Assembleia virtual às 19h'],
        ['day' => '15', 'month' => 'nov', 'title' => 'Envio das prestações de contas', 'text' => 'Competência outubro'],
    ],
    'notifications' => [
        ['title' => 'Retorno bancário disponível', 'text' => 'Banco fictício · 06/11', 'href' => '/cobranca/retornos'],
        ['title' => 'Nova ocorrência registrada', 'text' => 'Condomínio Vila Rica', 'href' => '/operacional/ocorrencias'],
        ['title' => 'Comunicado agendado', 'text' => 'Edifício Solar do Atlântico · amanhã', 'href' => '/comunicacao/comunicados-gerais'],
    ],
];
