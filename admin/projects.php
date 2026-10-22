<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('projects.view');

$canManage = userCan('projects.manage');
$db = getDB();
$projects = $db->query("
  SELECT p.*, u.name AS created_by_name,
    (SELECT COUNT(*) FROM leads WHERE project_id = p.id) AS lead_count
  FROM projects p LEFT JOIN users u ON u.id = p.created_by
  ORDER BY p.created_at DESC
")->fetchAll();

$pageTitle = 'Projects';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div>
    <h4 class="fw-800 mb-0">Projects</h4>
    <div class="text-muted-sm">Builder projects &amp; unit inventory tracking</div>
  </div>
  <?php if ($canManage): ?>
  <button class="btn btn-accent" data-bs-toggle="modal" data-bs-target="#projectModal" onclick="openProjectModal()">
    <i class="bi bi-plus-lg me-1"></i> Add Project
  </button>
  <?php endif; ?>
</div>

<div class="row g-3 mb-1">
  <?php foreach ($projects as $p):
    $pct = $p['total_units'] > 0 ? round(($p['sold_units'] / $p['total_units']) * 100) : 0;
  ?>
  <div class="col-md-6 col-xl-4">
    <div class="card-panel h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-2">
          <div>
            <div class="fw-700"><?= e($p['name']) ?></div>
            <div class="text-muted-sm"><?= e($p['project_code']) ?></div>
          </div>
          <span class="badge-status badge-<?= $p['status'] === 'active' ? 'active' : ($p['status'] === 'completed' ? 'converted' : 'pending') ?>">
            <?= e(str_replace('_',' ',$p['status'])) ?>
          </span>
        </div>
        <div class="text-muted-sm mb-1"><i class="bi bi-building me-1"></i><?= e($p['builder'] ?: '-') ?></div>
        <div class="text-muted-sm mb-2"><i class="bi bi-geo-alt me-1"></i><?= e($p['location'] ?: '-') ?></div>
        <div class="mb-3"><span class="badge-status badge-pending"><?= e(str_replace('_',' ', ucwords($p['category'] ?? 'general'))) ?></span></div>

        <div class="d-flex justify-content-between fs-13 mb-1">
          <span>Units Sold</span><span class="fw-600"><?= (int)$p['sold_units'] ?> / <?= (int)$p['total_units'] ?></span>
        </div>
        <div class="progress mb-2" style="height:6px;">
          <div class="progress-bar bg-success" style="width: <?= $pct ?>%"></div>
        </div>
        <div class="row g-2 text-center fs-13 mt-2">
          <div class="col-4">
            <div class="fw-700"><?= (int)$p['available_units'] ?></div>
            <div class="text-muted-sm">Available</div>
          </div>
          <div class="col-4">
            <div class="fw-700"><?= (int)$p['rental_units'] ?></div>
            <div class="text-muted-sm">Rental</div>
          </div>
          <div class="col-4">
            <div class="fw-700"><?= (int)$p['lead_count'] ?></div>
            <div class="text-muted-sm">Leads</div>
          </div>
        </div>
        <?php if ($canManage): ?>
        <div class="d-flex gap-2 mt-3">
          <button class="btn btn-sm btn-soft flex-fill" onclick='editProject(<?= json_encode($p) ?>)'><i class="bi bi-pencil me-1"></i>Edit</button>
          <button class="btn btn-sm btn-light text-danger flex-fill" onclick="deleteProject(<?= $p['id'] ?>)"><i class="bi bi-trash me-1"></i>Delete</button>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!$projects): ?>
    <div class="col-12"><div class="card-panel"><div class="card-body text-center text-muted-sm py-4">No projects added yet.</div></div></div>
  <?php endif; ?>
</div>

<?php if ($canManage): ?>
<!-- Project Modal -->
<div class="modal fade" id="projectModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content">
      <form id="projectForm">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="id" id="projectId">
        <div class="modal-header">
          <h5 class="modal-title fw-700" id="projectModalTitle">Add Project</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-600 fs-13">Project Name *</label>
              <input type="text" name="name" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13">Builder</label>
              <input type="text" name="builder" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600 fs-13">Location</label>
              <input type="text" name="location" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label fw-600 fs-13">Category</label>
              <select name="category" class="form-select">
                <option value="general">General (show for every requirement category)</option>
                <option value="house_rent_requirement">House Rent Requirement</option>
                <option value="commercial_space_requirement">Commercial Space Requirement</option>
                <option value="flat_lease">Flat Lease</option>
                <option value="flat_selling">Flat Selling</option>
                <option value="land_selling">Land Selling</option>
              </select>
              <div class="form-text">Controls which projects show up in the Add/Edit Lead "Project" dropdown once a Requirement Category is picked.</div>
            </div>
            <div class="col-md-3 col-6">
              <label class="form-label fw-600 fs-13">Total Units</label>
              <input type="number" name="total_units" class="form-control" value="0">
            </div>
            <div class="col-md-3 col-6">
              <label class="form-label fw-600 fs-13">Available</label>
              <input type="number" name="available_units" class="form-control" value="0">
            </div>
            <div class="col-md-3 col-6">
              <label class="form-label fw-600 fs-13">Sold</label>
              <input type="number" name="sold_units" class="form-control" value="0">
            </div>
            <div class="col-md-3 col-6">
              <label class="form-label fw-600 fs-13">Rental</label>
              <input type="number" name="rental_units" class="form-control" value="0">
            </div>
            <div class="col-12">
              <label class="form-label fw-600 fs-13">Status</label>
              <select name="status" class="form-select">
                <option value="active">Active</option>
                <option value="completed">Completed</option>
                <option value="on_hold">On Hold</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-accent">Save Project</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
<script src="<?= BASE_URL ?>/assets/js/projects.js"></script>
