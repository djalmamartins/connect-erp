<?php
/** Resolva fácil: os serviços do condomínio num lugar só (revisão de 15/11, igual ao app de celular). @var array $link */
$this->layout('default');
$on = static fn (string $feature): bool => in_array($feature, $link['features'], true);
$items = array_values(array_filter([
    $on('documents') ? ['Documentos', app_url('/documentos'), 'file', 'Atas, regimento e contratos'] : null,
    $on('accountability') ? ['Prestação de contas', app_url('/prestacao-de-contas'), 'chart', 'Balancetes do condomínio'] : null,
    $on('assemblies') ? ['Assembleia digital', app_url('/assembleias'), 'users', 'Editais e votações'] : null,
    $on('boletos') ? ['Boletos', app_url('/boletos'), 'receipt', '2ª via e recibos'] : null,
    $on('reservations') ? ['Reservas', app_url('/reservas'), 'calendar', 'Salão, churrasqueira e quadra'] : null,
    ['CND', null, 'shield'],
    $on('occurrences') ? ['Chamado', app_url('/ocorrencias'), 'alert', 'Fale com a administração'] : null,
    ['Ocorrência', null, 'flag'],
    $on('announcements') ? ['Comunicados', app_url('/comunicados'), 'megaphone', 'Avisos do condomínio'] : null,
]));
?>
<header class="app-page-head"><span class="app-eyebrow"><?= $this->e($link['condominium_name']) ?></span><h1>Resolva fácil</h1><p>Os serviços do condomínio num lugar só.</p></header>
<?php $this->insert('partials/actions', ['items' => $items, 'label' => 'Resolva fácil']) ?>
