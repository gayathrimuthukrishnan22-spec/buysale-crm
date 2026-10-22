</main>
</div><!-- /.app-shell -->

<!-- Shared "Description" quick-edit box (with change history) — opened from the
     3-dot Manage menu on Stock / Properties / Leads / Follow-ups / Employees tables -->
<div class="modal fade" id="descriptionModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-700"><i class="bi bi-card-text me-1 text-accent"></i>Description</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <textarea id="descriptionBoxText" class="form-control" rows="4" placeholder="Add a description / note..." maxlength="5000"></textarea>
        <div class="d-flex justify-content-end mt-2">
          <button type="button" class="btn btn-accent btn-sm" id="descriptionBoxSave"><i class="bi bi-check2 me-1"></i>Save</button>
        </div>
        <hr>
        <div class="fs-13 fw-600 text-muted-sm mb-2"><i class="bi bi-clock-history me-1"></i>History</div>
        <div id="descriptionBoxHistory"><div class="text-muted-sm fs-13">Loading...</div></div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/main.js?v=<?= @filemtime(__DIR__ . '/../assets/js/main.js') ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/description-box.js?v=<?= @filemtime(__DIR__ . '/../assets/js/description-box.js') ?>"></script>
</body>
</html>
