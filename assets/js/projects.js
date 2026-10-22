(function () {
  const BASE_URL = document.body.dataset.baseUrl || '';
  const projectForm = document.getElementById('projectForm');
  const projectModalEl = document.getElementById('projectModal');
  const projectModal = projectModalEl ? new bootstrap.Modal(projectModalEl) : null;

  window.openProjectModal = function () {
    projectForm.reset();
    document.getElementById('projectId').value = '';
    document.getElementById('projectModalTitle').textContent = 'Add Project';
  };

  window.editProject = function (p) {
    projectForm.reset();
    document.getElementById('projectId').value = p.id;
    for (const key of ['name','builder','location','category','total_units','available_units','sold_units','rental_units','status']) {
      const field = projectForm.querySelector(`[name="${key}"]`);
      if (field) field.value = p[key] ?? '';
    }
    document.getElementById('projectModalTitle').textContent = 'Edit Project';
    projectModal.show();
  };

  window.deleteProject = function (id) {
    if (!confirm('Delete this project? Leads linked to it will be unlinked, not deleted.')) return;
    const fd = new FormData();
    fd.append('id', id);
    fd.append('csrf_token', projectForm.querySelector('[name=csrf_token]').value);
    fetch(`${BASE_URL}/api/projects/delete.php`, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(res => { alert(res.message); if (res.success) location.reload(); });
  };

  if (projectForm) {
    projectForm.addEventListener('submit', function (e) {
      e.preventDefault();
      const fd = new FormData(projectForm);
      fetch(`${BASE_URL}/api/projects/save.php`, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
          if (!res.success) { alert(res.message); return; }
          location.reload();
        });
    });
  }
})();
