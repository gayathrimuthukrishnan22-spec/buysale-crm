/**
 * Shared "Description" quick-edit box, opened from the 3-dot Manage menu on
 * any list table. Usage from a row's dropdown:
 *   openDescriptionBox('property', row.id, row.property_code)
 *   openDescriptionBox('lead', row.id, row.lead_code)
 *   openDescriptionBox('followup', row.id)
 *   openDescriptionBox('user', row.id, row.name)
 *
 * Every save is timestamped and attributed automatically by the server
 * (api/common/description.php), and the modal always shows the full
 * change history underneath the editable box.
 */
(function () {
  const BASE_URL = document.body.dataset.baseUrl || '';
  const modalEl = document.getElementById('descriptionModal');
  if (!modalEl) return;

  const modal = new bootstrap.Modal(modalEl);
  const titleEl = modalEl.querySelector('.modal-title');
  const textEl = document.getElementById('descriptionBoxText');
  const saveBtn = document.getElementById('descriptionBoxSave');
  const historyEl = document.getElementById('descriptionBoxHistory');

  let currentType = null;
  let currentId = null;

  function escapeHtml(str) {
    return (str || '').toString().replace(/[&<>"']/g, m => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[m]));
  }

  function renderHistory(history) {
    if (!history || !history.length) {
      historyEl.innerHTML = '<div class="text-muted-sm fs-13">No changes recorded yet.</div>';
      return;
    }
    historyEl.innerHTML = `<div class="list-group list-group-flush">` + history.map(h => `
      <div class="list-group-item px-0 py-2">
        <div class="fs-13" style="white-space:pre-wrap;">${h.value ? escapeHtml(h.value) : '<span class="text-muted-sm">(cleared)</span>'}</div>
        <div class="text-muted-sm" style="font-size:11px;" title="${escapeHtml(h.at)}">
          <i class="bi bi-person me-1"></i>${escapeHtml(h.by)} &middot; ${escapeHtml(h.ago)}
        </div>
      </div>
    `).join('') + `</div>`;
  }

  window.openDescriptionBox = function (entityType, entityId, label) {
    currentType = entityType;
    currentId = entityId;
    titleEl.innerHTML = `<i class="bi bi-card-text me-1 text-accent"></i>Description${label ? ' — ' + escapeHtml(label) : ''}`;
    textEl.value = '';
    textEl.disabled = true;
    saveBtn.disabled = true;
    historyEl.innerHTML = '<div class="text-muted-sm fs-13">Loading...</div>';
    modal.show();

    fetch(`${BASE_URL}/api/common/description.php?entity_type=${encodeURIComponent(entityType)}&entity_id=${encodeURIComponent(entityId)}`)
      .then(r => r.json())
      .then(res => {
        if (!res.success) {
          historyEl.innerHTML = `<div class="text-danger fs-13">${escapeHtml(res.message)}</div>`;
          return;
        }
        textEl.value = res.data.description || '';
        textEl.disabled = !res.data.can_edit;
        saveBtn.disabled = !res.data.can_edit;
        saveBtn.style.display = res.data.can_edit ? '' : 'none';
        renderHistory(res.data.history);
      })
      .catch(() => {
        historyEl.innerHTML = '<div class="text-danger fs-13">Could not load description.</div>';
      });
  };

  saveBtn.addEventListener('click', function () {
    if (!currentType || !currentId) return;
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Saving...';

    const fd = new FormData();
    fd.append('entity_type', currentType);
    fd.append('entity_id', currentId);
    fd.append('description', textEl.value);
    fd.append('csrf_token', window.CSRF_TOKEN || '');

    fetch(`${BASE_URL}/api/common/description.php`, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(res => {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="bi bi-check2 me-1"></i>Save';
        if (!res.success) { alert(res.message); return; }
        // Reload history so the fresh entry (with time/by) shows immediately.
        window.openDescriptionBox(currentType, currentId, titleEl.textContent.replace(/^Description\s*—\s*/, '').trim());
      })
      .catch(() => {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="bi bi-check2 me-1"></i>Save';
        alert('Could not save description. Please try again.');
      });
  });
})();
