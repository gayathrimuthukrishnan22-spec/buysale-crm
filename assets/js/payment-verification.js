(function () {
  const BASE_URL = document.body.dataset.baseUrl || window.APP_BASE_URL || '';
  const tbody = document.getElementById('pvTableBody');
  const pagination = document.getElementById('pvPagination');
  const searchInput = document.getElementById('pvSearch');
  const statusSelect = document.getElementById('pvStatus');

  let currentPage = 1;
  let debounceTimer = null;

  function escapeHtml(str) {
    return (str || '').toString().replace(/[&<>"']/g, m => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[m]));
  }

  function fmtMoney(v) {
    if (v === null || v === undefined || v === '') return '-';
    return '₹' + Number(v).toLocaleString('en-IN');
  }

  function fmtDate(v) {
    if (!v) return '-';
    const d = new Date(v.replace(' ', 'T'));
    return d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
  }

  function statusBadge(status) {
    const cls = status === 'rejected' ? 'cancelled' : 'new';
    return `<span class="badge-status badge-${cls}">${escapeHtml(status === 'pending' ? 'Pending' : 'Rejected')}</span>`;
  }

  function renderRows(rows) {
    if (!rows.length) {
      tbody.innerHTML = `<tr><td colspan="8" class="text-center py-4 text-muted-sm">Nothing waiting on verification.</td></tr>`;
      return;
    }
    tbody.innerHTML = rows.map(r => `
      <tr>
        <td data-label="Lead ID" class="fw-600">${escapeHtml(r.lead_code)}</td>
        <td data-label="Client">
          <div class="client-name">${escapeHtml(r.client_name)}</div>
          <div class="text-muted-sm">${escapeHtml(r.client_mobile)}</div>
        </td>
        <td data-label="Requirement">${escapeHtml((r.requirement || '').toUpperCase())}${r.property_type ? ' &middot; ' + escapeHtml(r.property_type) : ''}</td>
        <td data-label="Amount">${fmtMoney(r.amount)}<div class="text-muted-sm">${escapeHtml(r.method || '')}</div></td>
        <td data-label="Proof">${r.screenshot_path
          ? `<a class="btn btn-sm btn-outline-accent" href="${BASE_URL}/uploads/${r.screenshot_path}" target="_blank" rel="noopener"><i class="bi bi-eye me-1"></i>View</a>`
          : '<span class="text-muted-sm">No proof</span>'}</td>
        <td data-label="Submitted">${fmtDate(r.paid_at || r.created_at)}</td>
        <td data-label="Status">${statusBadge(r.payment_status)}</td>
        <td data-label="Action" class="text-end">
          <div class="d-flex gap-1 justify-content-end">
            <button class="btn btn-sm btn-success" data-verify="${r.payment_id}"><i class="bi bi-check-lg"></i> Verify</button>
            <button class="btn btn-sm btn-outline-danger" data-reject="${r.payment_id}"><i class="bi bi-x-lg"></i> Reject</button>
          </div>
        </td>
      </tr>`).join('');
  }

  function renderPagination(page, totalPages) {
    if (totalPages <= 1) { pagination.innerHTML = ''; return; }
    let html = '';
    for (let i = 1; i <= totalPages; i++) {
      html += `<li class="page-item ${i === page ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
    }
    pagination.innerHTML = html;
  }

  async function loadRows() {
    tbody.innerHTML = `<tr><td colspan="8" class="text-center py-4 text-muted-sm">Loading...</td></tr>`;
    const params = new URLSearchParams({
      search: searchInput.value.trim(),
      payment_status: statusSelect.value,
      page: currentPage,
    });
    try {
      const res = await fetch(`${BASE_URL}/api/leads/pending-payments.php?${params.toString()}`);
      const data = await res.json();
      if (!data.success) {
        tbody.innerHTML = `<tr><td colspan="8" class="text-center py-4 text-danger">${escapeHtml(data.message || 'Could not load.')}</td></tr>`;
        return;
      }
      renderRows(data.data.rows);
      renderPagination(data.data.page, data.data.total_pages);
    } catch (err) {
      tbody.innerHTML = `<tr><td colspan="8" class="text-center py-4 text-danger">Network error while loading.</td></tr>`;
    }
  }

  async function actOnPayment(paymentId, action) {
    if (action === 'reject' && !confirm('Reject this payment? The registration will stay out of Leads.')) return;
    const fd = new FormData();
    fd.append('csrf_token', window.CSRF_TOKEN || '');
    fd.append('payment_id', paymentId);
    fd.append('action', action);
    try {
      const res = await fetch(`${BASE_URL}/api/leads/verify-payment.php`, { method: 'POST', body: fd });
      const data = await res.json();
      if (!data.success) { alert(data.message || 'Action failed.'); return; }
      loadRows();
    } catch (err) {
      alert('Network error. Please try again.');
    }
  }

  tbody.addEventListener('click', (e) => {
    const verifyBtn = e.target.closest('[data-verify]');
    const rejectBtn = e.target.closest('[data-reject]');
    if (verifyBtn) actOnPayment(verifyBtn.dataset.verify, 'verify');
    else if (rejectBtn) actOnPayment(rejectBtn.dataset.reject, 'reject');
  });

  pagination.addEventListener('click', (e) => {
    const link = e.target.closest('[data-page]');
    if (!link) return;
    e.preventDefault();
    currentPage = parseInt(link.dataset.page, 10);
    loadRows();
  });

  searchInput.addEventListener('input', () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => { currentPage = 1; loadRows(); }, 350);
  });
  statusSelect.addEventListener('change', () => { currentPage = 1; loadRows(); });

  loadRows();
})();
