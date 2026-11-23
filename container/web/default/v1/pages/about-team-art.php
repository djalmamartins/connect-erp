<?php
/**
 * Ilustrações da seção "Especialistas em gestão condominial" (ADR 0647).
 * Desenhos próprios da Connect (SVG em assets/images/gestao), sem foto de banco de imagens
 * nem imagem gerada por terceiros. Cada quadro mostra uma parte do serviço e leva uma legenda.
 */
$aboutTeamArt = [
    [
        "class" => "about-team-image-main",
        "file" => "prestacao-de-contas.svg",
        "width" => 840,
        "height" => 300,
        "alt" => "Ilustração: prédios do condomínio ligados ao painel com as contas do mês, o boleto e o selo de conferido.",
        "caption" => "Prestação de contas todo mês",
    ],
    [
        "class" => "about-team-image-bottom-left",
        "file" => "assembleia.svg",
        "width" => 420,
        "height" => 250,
        "alt" => "Ilustração: condôminos em volta da mesa, com a pauta no quadro e um voto de mão levantada.",
        "caption" => "Assembleias organizadas",
    ],
    [
        "class" => "about-team-image-bottom-right",
        "file" => "chamados.svg",
        "width" => 420,
        "height" => 250,
        "alt" => "Ilustração: chamado aberto no celular, ao lado da lista de manutenção com os itens conferidos.",
        "caption" => "Chamados acompanhados pelo app",
    ],
];
?>
<div class="about-team-images">
    <?php foreach ($aboutTeamArt as $art): ?>
        <figure class="about-team-image <?= $art["class"]; ?>">
            <img src="<?= theme("/assets/images/gestao/" . $art["file"]); ?>"
                 width="<?= $art["width"]; ?>" height="<?= $art["height"]; ?>"
                 alt="<?= $art["alt"]; ?>" loading="lazy" decoding="async">
            <figcaption class="about-team-caption"><?= $art["caption"]; ?></figcaption>
        </figure>
    <?php endforeach; ?>
</div>
