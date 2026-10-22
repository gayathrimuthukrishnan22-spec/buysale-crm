<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('employees.manage');

$canEditPermissions = userCan('employees.permissions');
$db = getDB();

$employees = $db->query("
  SELECT u.*, m.name AS manager_name,
    (SELECT COUNT(*) FROM leads WHERE assigned_to = u.id) AS lead_count,
    (SELECT COUNT(*) FROM leads WHERE assigned_to = u.id AND status='deal_closed') AS converted_count
  FROM users u LEFT JOIN users m ON m.id = u.reports_to
  WHERE u.id != " . (int)currentUserId() . "
  ORDER BY FIELD(u.role,'super_admin','admin','manager','sales_executive','telecaller','employee','accountant'), u.created_at DESC
")->fetchAll();

// Permission catalogue + per-role defaults, embedded for the JS-driven permission editor
$allPermissions = $db->query("SELECT `key`, label, module FROM permissions ORDER BY module, label")->fetchAll();
$roleDefaults = [];
foreach (allRoles() as $r) {
    $stmt = $db->prepare("SELECT permission_key FROM role_permissions WHERE role = ?");
    $stmt->execute([$r]);
    $roleDefaults[$r] = array_column($stmt->fetchAll(), 'permission_key');
}
$managers = $db->query("SELECT id, name FROM users WHERE role IN ('super_admin','admin','manager') ORDER BY name")->fetchAll();

// Which roles can the current user assign? Only super_admin can create other super_admins/admins.
$assignableRoles = isSuperAdmin() ? allRoles() : array_values(array_diff(allRoles(), ['super_admin']));

$pageTitle = 'Employees';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div>
    <h4 class="fw-800 mb-0">Team Management</h4>
    <div class="text-muted-sm">Manage accounts, roles, and permission overrides</div>
  </div>
  <button class="btn btn-accent" data-bs-toggle="modal" data-bs-target="#empModal" onclick="openEmpModal()">
    <i class="bi bi-person-plus me-1"></i> Add Team Member
  </button>
</div>

<div class="table-responsive-modern">
  <table class="table table-modern align-middle">
    <thead>
      <tr>
        <th>Name</th><th>Username</th><th>Role</th><th>Reports To</th><th>Leads</th><th>Closed</th><th>Status</th><th>Last Login</th><th class="text-end">Manage</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$employees): ?>
        <tr><td colspan="9" class="text-center py-4 text-muted-sm">No team members added yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($employees as $emp): ?>
        <tr>
          <td data-label="Name" class="fw-600"><?= e($emp['name']) ?></td>
          <td data-label="Username"><?= e($emp['username']) ?></td>
          <td data-label="Role"><span class="badge-status badge-active"><?= e(roleLabel($emp['role'])) ?></span></td>
          <td data-label="Reports To"><?= e($emp['manager_name'] ?? '-') ?></td>
          <td data-label="Leads"><?= (int)$emp['lead_count'] ?></td>
          <td data-label="Closed"><span class="badge-status badge-deal_closed"><?= (int)$emp['converted_count'] ?></span></td>
          <td data-label="Status"><span class="badge-status badge-<?= $emp['status'] === 'active' ? 'active' : 'cancelled' ?>"><?= e($emp['status']) ?></span></td>
          <td data-label="Last Login" class="text-muted-sm"><?= $emp['last_login'] ? timeAgo($emp['last_login']) : 'Never' ?></td>
          <td data-label="Manage" class="text-end">
            <div class="dropdown">
              <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
              <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="#" onclick='editEmp(<?= json_encode([
                  "id" => $emp["id"], "name" => $emp["name"], "username" => $emp["username"],
                  "mobile" => $emp["mobile"], "email" => $emp["email"], "role" => $emp["role"],
                  "reports_to" => $emp["reports_to"], "permissions" => $emp["permissions"],
              ]) ?>);return false;'><i class="bi bi-pencil me-2"></i>Edit</a></li>
                <li><a class="dropdown-item" href="#" onclick="openDescriptionBox('user', <?= $emp['id'] ?>, '<?= e(addslashes($emp['name'])) ?>');return false;"><i class="bi bi-card-text me-2"></i>Description</a></li>
                <li><a class="dropdown-item" href="#" onclick="toggleEmpStatus(<?= $emp['id'] ?>, '<?= $emp['status'] === 'active' ? 'disabled' : 'active' ?>');return false;">
                  <i class="bi bi-power me-2"></i><?= $emp['status'] === 'active' ? 'Disable' : 'Enable' ?>
                </a></li>
              </ul>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Employee Modal -->
<div class="modal fade" id="empModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form id="empForm">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="id" id="empId">
        <div class="modal-header">
          <h5 class="modal-title fw-700" id="empModalTitle">Add Team Member</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="form-section-label">Account Details</div>
          <div class="row g-3 mb-2">
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13">Full Name *</label>
              <input type="text" name="name" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13">Username *</label>
              <input type="text" name="username" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13">Mobile</label>
              <input type="text" name="mobile" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13">Email</label>
              <input type="email" name="email" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13" id="pwdLabel">Password *</label>
              <input type="password" name="password" id="empPassword" class="form-control" minlength="6">
              <div class="form-text">Leave blank when editing to keep the current password.</div>
            </div>
          </div>

          <div class="form-section-label">Role &amp; Hierarchy</div>
          <div class="row g-3 mb-2">
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13">Role *</label>
              <select name="role" id="empRole" class="form-select" required>
                <?php foreach ($assignableRoles as $r): ?>
                  <option value="<?= e($r) ?>"><?= e(roleLabel($r)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13">Reports To</label>
              <select name="reports_to" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($managers as $m): ?>
                  <option value="<?= $m['id'] ?>"><?= e($m['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <?php if ($canEditPermissions): ?>
          <div class="form-section-label">
            Permission Overrides
            <span class="fw-400 normal-case text-muted-sm d-block mt-1" style="letter-spacing:normal;text-transform:none;font-size:.8rem;">
              Checked = granted. Defaults are pre-filled from the selected role — tick or untick to customize for this person only.
            </span>
          </div>
          <div id="permissionGrid" class="permission-grid"></div>
          <input type="hidden" name="permissions_editor_present" value="1">
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-accent">Save Team Member</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
  window.ALL_PERMISSIONS = <?= json_encode($allPermissions) ?>;
  window.ROLE_DEFAULTS = <?= json_encode($roleDefaults) ?>;
  window.CAN_EDIT_PERMISSIONS = <?= $canEditPermissions ? 'true' : 'false' ?>;
</script>
<script src="<?= BASE_URL ?>/assets/js/employees.js"></script>
