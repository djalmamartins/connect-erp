<?php
/** Identificação da Connect (Configurações do Studio, ADR 0600). Campo vazio não aparece. */
$legalCompany = CONF_SITE_COMPANY_NAME ?: CONF_SITE_NAME;
$legalAddress = trim(CONF_SITE_ADDR_STREET . ', ' . CONF_SITE_ADDR_NUMBER . (CONF_SITE_ADDR_COMPLEMENT ? ' - ' . CONF_SITE_ADDR_COMPLEMENT : '') . ' - ' . CONF_SITE_ADDR_DISTRICT . ', ' . CONF_SITE_ADDR_CITY . ' - ' . CONF_SITE_ADDR_STATE . ', ' . CONF_SITE_ADDR_ZIPCODE, ' ,-');
$legalPrivacyEmail = CONF_PRIVACY_EMAIL ?: CONF_MAIL_SUPPORT;
?>
<p><strong><?= htmlspecialchars($legalCompany) ?></strong><?php if (CONF_SITE_CNPJ): ?>, CNPJ <?= htmlspecialchars(CONF_SITE_CNPJ) ?><?php endif; ?><?php if ($legalAddress): ?>, <?= htmlspecialchars($legalAddress) ?><?php endif; ?>.</p>
<p>Encarregado pelo tratamento de dados pessoais<?= CONF_PRIVACY_OFFICER ? ': <strong>' . htmlspecialchars(CONF_PRIVACY_OFFICER) . '</strong>' : '' ?><?php if ($legalPrivacyEmail): ?>, pelo e-mail <a href="mailto:<?= htmlspecialchars($legalPrivacyEmail) ?>"><?= htmlspecialchars($legalPrivacyEmail) ?></a><?php endif; ?><?php if (CONF_SITE_PHONE): ?> ou pelo telefone <?= htmlspecialchars(CONF_SITE_PHONE) ?><?php endif; ?>.</p>
