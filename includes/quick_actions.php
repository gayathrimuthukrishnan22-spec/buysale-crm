<?php
/**
 * Renders Call + WhatsApp icon buttons for a client mobile number.
 * Usage: renderQuickActions($row['client_mobile'], $row['client_name']);
 */
function renderQuickActions(string $mobile, string $clientName = '', string $context = ''): void {
    if (empty($mobile)) {
        echo '<span class="text-muted-sm">-</span>';
        return;
    }
    $telNumber = preg_replace('/[^0-9+]/', '', $mobile);
    $waNumber  = normalizeMobileForLink($mobile);
    $greeting  = trim("Hello " . $clientName . ($context ? ", regarding your $context requirement." : ", this is regarding your property enquiry."));
    $waLink    = 'https://wa.me/' . $waNumber . '?text=' . rawurlencode($greeting);
    ?>
    <div class="quick-actions">
      <a href="tel:<?= e($telNumber) ?>" class="btn-call" title="Call <?= e($clientName) ?>" data-bs-toggle="tooltip">
        <i class="bi bi-telephone-fill"></i>
      </a>
      <a href="<?= e($waLink) ?>" target="_blank" rel="noopener" class="btn-whatsapp" title="WhatsApp <?= e($clientName) ?>" data-bs-toggle="tooltip">
        <i class="bi bi-whatsapp"></i>
      </a>
    </div>
    <?php
}
