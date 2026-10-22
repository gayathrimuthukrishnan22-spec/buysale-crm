<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('audit.view');

$db = getDB();
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$total = (int)$db->query("SELECT COUNT(*) FROM activity_log")->fetchColumn();
$logs = $db->query("
  SELECT a.*, u.name AS performed_by_name FROM activity_log a
  LEFT JOIN users u ON u.id = a.performed_by
  ORDER BY a.created_at DESC LIMIT $perPage OFFSET $offset
")->fetchAll();

$totalPages = (int)ceil($total / $perPage);
$pageTitle = 'Audit Log';
include __DIR__ . '/../includes/header.php';
?>

<h4 class="fw-800 mb-1">Audit Log</h4>
<div class="text-muted-sm mb-4">Complete change history across the entire system for compliance and security review.</div>

<div class="table-responsive-modern">
  <table class="table table-modern align-middle">
    <thead>
      <tr><th>Entity</th><th>Action</th><th>Field</th><th>Old → New</th><th>Performed By</th><th>IP</th><th>When</th></tr>
    </thead>
    <tbody>
      <?php if (!$logs): ?>
        <tr><td colspan="7" class="text-center py-4 text-muted-sm">No activity recorded yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($logs as $l): ?>
        <tr>
          <td data-label="Entity" class="text-capitalize fw-600"><?= e($l['entity_type']) ?> #<?= (int)$l['entity_id'] ?></td>
          <td data-label="Action" class="text-capitalize"><?= e(str_replace('_',' ',$l['action'])) ?></td>
          <td data-label="Field" class="text-muted-sm"><?= e($l['field_changed'] ?? '-') ?></td>
          <td data-label="Old → New" class="fs-13"><?= ($l['old_value'] || $l['new_value']) ? e($l['old_value']).' → '.e($l['new_value']) : '-' ?></td>
          <td data-label="Performed By"><?= e($l['performed_by_name'] ?? 'System') ?></td>
          <td data-label="IP" class="text-muted-sm"><?= e($l['ip_address'] ?? '-') ?></td>
          <td data-label="When" class="text-muted-sm"><?= date('d M Y, h:i A', strtotime($l['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<nav class="mt-3">
  <ul class="pagination justify-content-end">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
      <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a></li>
    <?php endfor; ?>
  </ul>
</nav>

<?php include __DIR__ . '/../includes/footer.php'; ?>
