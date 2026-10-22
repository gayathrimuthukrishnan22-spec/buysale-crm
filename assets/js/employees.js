(function () {
  const BASE_URL = document.body.dataset.baseUrl || '';
  const empForm = document.getElementById('empForm');
  const empModalEl = document.getElementById('empModal');
  const empModal = empModalEl ? new bootstrap.Modal(empModalEl) : null;
  const empRoleEl = document.getElementById('empRole');
  const permissionGrid = document.getElementById('permissionGrid');
  const CAN_EDIT_PERMISSIONS = !!window.CAN_EDIT_PERMISSIONS;

  function escapeHtml(str) {
    return (str || '').toString().replace(/[&<>"']/g, m => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[m]));
  }

  /** Render one checkbox per permission key, grouped by module, pre-checked per `checkedKeys`. */
  function renderPermissionGrid(checkedKeys) {
    if (!permissionGrid || !CAN_EDIT_PERMISSIONS) return;
    const checked = new Set(checkedKeys);
    let lastModule = null;
    let html = '';
    for (const perm of window.ALL_PERMISSIONS) {
      if (perm.module !== lastModule) {
        html += `<div class="permission-module-title">${escapeHtml(perm.module)}</div>`;
        lastModule = perm.module;
      }
      const isChecked = checked.has(perm.key) ? 'checked' : '';
      html += `
        <label class="permission-item">
          <input type="checkbox" name="perm_${perm.key}" value="${perm.key}" class="perm-checkbox" ${isChecked}>
          ${escapeHtml(perm.label)}
        </label>`;
    }
    permissionGrid.innerHTML = html;
  }

  function refreshPermissionsForRole(role, existingOverrideChecked) {
    // existingOverrideChecked: if provided (editing an existing user), use it as-is;
    // otherwise default to the role's own default permission set.
    const defaults = window.ROLE_DEFAULTS[role] || [];
    renderPermissionGrid(existingOverrideChecked || defaults);
  }

  if (empRoleEl) {
    empRoleEl.addEventListener('change', () => refreshPermissionsForRole(empRoleEl.value));
  }

  window.openEmpModal = function () {
    empForm.reset();
    document.getElementById('empId').value = '';
    document.getElementById('empPassword').required = true;
    document.getElementById('pwdLabel').textContent = 'Password *';
    document.getElementById('empModalTitle').textContent = 'Add Team Member';
    refreshPermissionsForRole(empRoleEl ? empRoleEl.value : 'employee');
  };

  window.editEmp = function (emp) {
    empForm.reset();
    document.getElementById('empId').value = emp.id;
    empForm.querySelector('[name=name]').value = emp.name || '';
    empForm.querySelector('[name=username]').value = emp.username || '';
    empForm.querySelector('[name=mobile]').value = emp.mobile || '';
    empForm.querySelector('[name=email]').value = emp.email || '';
    if (empRoleEl) empRoleEl.value = emp.role || 'employee';
    const reportsToEl = empForm.querySelector('[name=reports_to]');
    if (reportsToEl) reportsToEl.value = emp.reports_to || '';
    document.getElementById('empPassword').required = false;
    document.getElementById('pwdLabel').textContent = 'Password';
    document.getElementById('empModalTitle').textContent = 'Edit Team Member';

    // Compute this user's EFFECTIVE granted permissions (role defaults + grant - revoke) to pre-check the grid
    const roleDefaults = window.ROLE_DEFAULTS[emp.role] || [];
    let effective = new Set(roleDefaults);
    if (emp.permissions) {
      try {
        const overrides = JSON.parse(emp.permissions);
        (overrides.grant || []).forEach(k => effective.add(k));
        (overrides.revoke || []).forEach(k => effective.delete(k));
      } catch (e) { /* ignore malformed override JSON */ }
    }
    renderPermissionGrid(Array.from(effective));
    empModal.show();
  };

  window.toggleEmpStatus = function (id, newStatus) {
    if (!confirm(`Are you sure you want to ${newStatus === 'active' ? 'enable' : 'disable'} this team member?`)) return;
    const fd = new FormData();
    fd.append('id', id);
    fd.append('status', newStatus);
    fd.append('csrf_token', empForm.querySelector('[name=csrf_token]').value);
    fetch(`${BASE_URL}/api/employees/toggle_status.php`, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(res => { alert(res.message); if (res.success) location.reload(); });
  };

  if (empForm) {
    empForm.addEventListener('submit', function (e) {
      e.preventDefault();
      const fd = new FormData(empForm);
      // Collect checked permission keys as a repeated field
      if (CAN_EDIT_PERMISSIONS) {
        document.querySelectorAll('.perm-checkbox:checked').forEach(cb => fd.append('granted_permissions[]', cb.value));
      }
      fetch(`${BASE_URL}/api/employees/save.php`, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
          if (!res.success) { alert(res.message); return; }
          location.reload();
        });
    });
  }
})();
