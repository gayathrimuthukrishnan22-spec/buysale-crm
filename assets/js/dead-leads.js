(function () {
  const BASE_URL = document.body.dataset.baseUrl || '';
  const SUBSCRIPTION_AMOUNT_DISPLAY = window.SUBSCRIPTION_AMOUNT || 1000;
  const SUBSCRIPTION_MATCH_LIMIT = window.SUBSCRIPTION_MATCH_LIMIT || 2;
  const tbody = document.getElementById('deadTableBody');
  const pagination = document.getElementById('deadPagination');
  const propForm = document.getElementById('propForm');
  const propModalEl = document.getElementById('propModal');
  const propModal = propModalEl ? new bootstrap.Modal(propModalEl) : null;

  const categoryLabels = { buy: 'Buy', sale: 'Sale', rental: 'Rental', lease: 'Lease', rental_tenant: 'Tenant Rental' };

  let currentPage = 1;
  let debounceTimer = null;

  function escapeHtml(str) {
    return (str || '').toString().replace(/[&<>"']/g, m => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[m]));
  }
  function normalizeMobileForLink(mobile) {
    const digits = (mobile || '').replace(/[^0-9]/g, '');
    return digits.length === 10 ? '91' + digits : digits;
  }
  function quickActionsHtml(mobile, name) {
    if (!mobile) return '<span class="text-muted-sm">-</span>';
    const tel = mobile.replace(/[^0-9+]/g, '');
    const wa = normalizeMobileForLink(mobile);
    const greeting = encodeURIComponent(`Hello ${name || ''}, this is regarding your property enquiry.`);
    return `
      <div class="quick-actions">
        <a href="tel:${tel}" class="btn-call" title="Call ${escapeHtml(name)}"><i class="bi bi-telephone-fill"></i></a>
        <a href="https://wa.me/${wa}?text=${greeting}" target="_blank" rel="noopener" class="btn-whatsapp" title="WhatsApp ${escapeHtml(name)}"><i class="bi bi-whatsapp"></i></a>
      </div>`;
  }
  function fmtMoney(v) {
    if (v === null || v === undefined || v === '') return '-';
    return '₹' + Number(v).toLocaleString('en-IN');
  }
  function fmtDate(v) {
    if (!v) return '-';
    return new Date(v.replace(' ', 'T')).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
  }

  function renderRows(rows) {
    if (!rows.length) {
      tbody.innerHTML = `<tr><td colspan="10" class="text-center py-4 text-muted-sm">No dead leads right now.</td></tr>`;
      return;
    }
    tbody.innerHTML = rows.map(r => `
      <tr>
        <td data-label="Property ID" class="fw-600">${escapeHtml(r.property_code)}</td>
        <td data-label="Client">
          <div class="client-name">${escapeHtml(r.client_name)}</div>
          <div class="client-mobile">${escapeHtml(r.client_mobile)}</div>
        </td>
        <td data-label="Category"><span class="badge bg-light text-dark border">${escapeHtml(categoryLabels[r.category] || r.category)}</span></td>
        <td data-label="Type">${escapeHtml(r.property_type)}</td>
        <td data-label="Location">${escapeHtml(r.address)}${r.city ? ', ' + escapeHtml(r.city) : ''}</td>
        <td data-label="Budget">${fmtMoney(r.budget)}</td>
        <td data-label="Assigned">${r.assigned_name ? escapeHtml(r.assigned_name) : '<span class="text-muted-sm">Unassigned</span>'}</td>
        <td data-label="Last Updated" class="text-muted-sm">${fmtDate(r.updated_at)}</td>
        <td data-label="Contact" class="text-center">${quickActionsHtml(r.client_mobile, r.client_name)}</td>
        <td data-label="Manage" class="text-end">
          <div class="dropdown">
            <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="#" onclick="viewProperty(${r.id});return false;"><i class="bi bi-eye me-2"></i>View Details</a></li>
              <li><a class="dropdown-item" href="#" onclick="editProp(${r.id}, '${escapeHtml(r.category)}');return false;"><i class="bi bi-pencil me-2"></i>Edit</a></li>
              <li><a class="dropdown-item" href="#" onclick="openDescriptionBox('property', ${r.id}, '${escapeHtml(r.property_code)}');return false;"><i class="bi bi-card-text me-2"></i>Description</a></li>
              <li><a class="dropdown-item" href="#" onclick="findMatches(${r.id});return false;"><i class="bi bi-stars me-2"></i>Find Matching Stock</a></li>
              <li><a class="dropdown-item" href="#" onclick="renewSubscription(${r.id});return false;"><i class="bi bi-arrow-repeat me-2"></i>Renew Subscription (₹${SUBSCRIPTION_AMOUNT_DISPLAY})</a></li>
            </ul>
          </div>
        </td>
      </tr>
    `).join('');
  }

  function renderPagination(total_pages, page) {
    if (!pagination) return;
    let html = '';
    for (let i = 1; i <= total_pages; i++) {
      html += `<li class="page-item ${i === page ? 'active' : ''}"><a class="page-link" href="#" onclick="deadGoToPage(${i});return false;">${i}</a></li>`;
    }
    pagination.innerHTML = html;
  }

  window.deadGoToPage = function (p) { currentPage = p; loadDeadLeads(); };

  function getFilters() {
    return {
      search: document.getElementById('fSearch')?.value || '',
      category: document.getElementById('fCategory')?.value || '',
      employee: document.getElementById('fEmployee')?.value || '',
      date: document.getElementById('fDate')?.value || '',
      page: currentPage,
    };
  }

  function loadDeadLeads() {
    const params = new URLSearchParams(getFilters());
    tbody.innerHTML = `<tr><td colspan="10" class="text-center py-4 text-muted-sm">Loading...</td></tr>`;
    fetch(`${BASE_URL}/api/properties/dead_leads_list.php?` + params.toString())
      .then(r => r.json())
      .then(res => {
        if (!res.success) throw new Error(res.message);
        renderRows(res.data.rows);
        renderPagination(res.data.total_pages, res.data.page);
      })
      .catch(err => {
        tbody.innerHTML = `<tr><td colspan="10" class="text-center py-4 text-danger">${escapeHtml(err.message)}</td></tr>`;
      });
  }

  ['fCategory', 'fEmployee', 'fDate'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', () => { currentPage = 1; loadDeadLeads(); });
  });
  const searchEl = document.getElementById('fSearch');
  if (searchEl) searchEl.addEventListener('input', () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => { currentPage = 1; loadDeadLeads(); }, 400);
  });

  // Read-only mode for "View Details".
  function setPropFormReadOnly(readOnly) {
    propForm.querySelectorAll('input, select, textarea').forEach(el => {
      if (el.type === 'hidden') return;
      el.disabled = readOnly;
    });
    const saveBtn = propModalEl.querySelector('.modal-footer button[type="submit"]');
    if (saveBtn) saveBtn.style.display = readOnly ? 'none' : '';
    const cancelBtn = propModalEl.querySelector('.modal-footer button[data-bs-dismiss="modal"]');
    if (cancelBtn) cancelBtn.textContent = readOnly ? 'Close' : 'Cancel';
  }

  function loadPropIntoForm(id, readOnly, category) {
    fetch(`${BASE_URL}/api/properties/detail.php?id=${id}`)
      .then(r => r.json())
      .then(res => {
        if (!res.success) { alert(res.message); return; }
        const p = res.data.property;
        propForm.reset();
        document.getElementById('propId').value = p.id;
        // The lead's own category travels with it into the form so, on Save,
        // save.php's required category field stays what it already was —
        // editing here never moves a lead to a different category page.
        document.getElementById('propCategory').value = category || p.category;
        for (const key of ['client_name','client_mobile','property_type','address','city','budget','assigned_to','status','description','notes']) {
          const field = propForm.querySelector(`[name="${key}"]`);
          if (field) field.value = p[key] ?? '';
        }
        setPropFormReadOnly(readOnly);
        document.getElementById('propModalTitle').textContent = (readOnly ? 'View ' : 'Edit ') + 'Lead';
        propModal.show();
      });
  }

  window.editProp = function (id, category) { loadPropIntoForm(id, false, category); };
  window.viewProperty = function (id) { loadPropIntoForm(id, true); };

  const matchModalEl = document.getElementById('matchModal');
  const matchModal = matchModalEl ? new bootstrap.Modal(matchModalEl) : null;
  const matchModalBody = document.getElementById('matchModalBody');

  function scoreBadgeClass(score) {
    if (score >= 70) return 'badge-active';
    if (score >= 40) return 'badge-pending';
    return 'badge-cancelled';
  }

  let currentMatchClientId = null;

  window.findMatches = function (id) {
    if (!matchModal) return;
    currentMatchClientId = id;
    matchModalBody.innerHTML = `<div class="text-center py-4 text-muted-sm">Loading...</div>`;
    matchModal.show();
    fetch(`${BASE_URL}/api/properties/match_stock.php?id=${id}`)
      .then(r => r.json())
      .then(res => {
        if (!res.success) {
          matchModalBody.innerHTML = `<div class="text-danger">${escapeHtml(res.message)}</div>`;
          return;
        }
        const { client, matches, note, subscription } = res.data;
        let html = `<div class="mb-3 text-muted-sm">
          For <strong>${escapeHtml(client.client_name)}</strong> — ${escapeHtml(client.property_type || '')}
          ${client.city ? ', ' + escapeHtml(client.city) : ''}
          ${client.budget ? ' · Budget ' + fmtMoney(client.budget) : ''}
        </div>`;

        if (subscription) {
          if (subscription.exhausted) {
            html += `<div class="alert alert-danger d-flex justify-content-between align-items-center flex-wrap gap-2">
              <div><i class="bi bi-exclamation-triangle-fill me-1"></i>Subscription used up (${subscription.matches_used}/${subscription.match_limit} matches connected) — this lead is marked <strong>dead</strong>.</div>
              <button type="button" class="btn btn-sm btn-danger flex-shrink-0" onclick="renewSubscription(${client.id}, true)"><i class="bi bi-arrow-repeat me-1"></i>Renew Subscription (₹${SUBSCRIPTION_AMOUNT_DISPLAY})</button>
            </div>`;
          } else {
            html += `<div class="text-muted-sm mb-3"><i class="bi bi-check2-circle me-1"></i>${subscription.matches_used}/${subscription.match_limit} matches used this subscription</div>`;
          }
        }

        if (note) {
          html += `<div class="alert alert-light border">${escapeHtml(note)}</div>`;
        }
        if (matches.length) {
          const exhausted = subscription && subscription.exhausted;
          html += matches.map(m => `
            <div class="card-panel mb-2">
              <div class="card-body py-2">
                <div class="d-flex justify-content-between align-items-start gap-2">
                  <div>
                    <div class="fw-700">${escapeHtml(m.property_type)} <span class="text-muted-sm fw-400">(${escapeHtml(m.property_code)})</span></div>
                    <div class="text-muted-sm">${escapeHtml(m.address)}${m.city ? ', ' + escapeHtml(m.city) : ''}</div>
                    <div class="text-muted-sm">Owner: ${escapeHtml(m.client_name)}</div>
                    <div class="fw-600 mt-1">${fmtMoney(m.budget)}</div>
                    ${m.match_reasons && m.match_reasons.length ? `<div class="mt-1">${m.match_reasons.map(rs => `<span class="badge bg-light text-dark border me-1 mb-1">${escapeHtml(rs)}</span>`).join('')}</div>` : ''}
                  </div>
                  <div class="text-end">
                    ${m.is_connected
                      ? `<span class="badge-status badge-active"><i class="bi bi-check2-circle me-1"></i>Connected</span>`
                      : `<span class="badge-status ${scoreBadgeClass(m.match_score)}">${m.match_score}% match</span>`}
                    <div class="mt-2">${quickActionsHtml(m.client_mobile, m.client_name)}</div>
                    ${m.is_connected ? '' : `
                    <button type="button" class="btn btn-sm btn-accent mt-2" ${exhausted ? 'disabled' : ''} onclick="connectMatch(${client.id}, ${m.id})">
                      <i class="bi bi-link-45deg me-1"></i>Connect
                    </button>`}
                  </div>
                </div>
              </div>
            </div>
          `).join('');
        } else if (!note) {
          html += `<div class="text-center py-4 text-muted-sm">No matching stock found right now.</div>`;
        }
        matchModalBody.innerHTML = html;
      })
      .catch(err => {
        matchModalBody.innerHTML = `<div class="text-danger">${escapeHtml(err.message)}</div>`;
      });
  };

  function getCsrfToken() {
    return propForm ? propForm.querySelector('[name=csrf_token]').value : '';
  }

  window.connectMatch = function (clientId, stockId) {
    const fd = new FormData();
    fd.append('client_id', clientId);
    fd.append('stock_id', stockId);
    fd.append('csrf_token', getCsrfToken());
    fetch(`${BASE_URL}/api/properties/connect_match.php`, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(res => {
        alert(res.message);
        if (res.success) {
          loadDeadLeads();
          if (currentMatchClientId === clientId) window.findMatches(clientId);
        }
      });
  };

  // Renewing here brings the lead back to 'active' — it then simply stops
  // matching this page's "status = dead" filter and reappears on its own
  // category page (Buy/Rental/Lease/Tenant Rental) instead, with no extra
  // step needed: the category was never touched while it sat here dead.
  window.renewSubscription = function (id, reopenMatches) {
    if (!confirm(`Renew subscription for ₹${SUBSCRIPTION_AMOUNT_DISPLAY}? This gives ${SUBSCRIPTION_MATCH_LIMIT} new matches and moves this lead back to its own category list.`)) return;
    const fd = new FormData();
    fd.append('client_id', id);
    fd.append('csrf_token', getCsrfToken());
    fetch(`${BASE_URL}/api/properties/renew_subscription.php`, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(res => {
        alert(res.message);
        if (res.success) {
          loadDeadLeads();
          if (reopenMatches) window.findMatches(id);
        }
      });
  };

  window.deleteProp = function (id) {
    if (!confirm('Delete this lead? This cannot be undone.')) return;
    const fd = new FormData();
    fd.append('id', id);
    fd.append('csrf_token', getCsrfToken());
    fetch(`${BASE_URL}/api/properties/delete.php`, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(res => { alert(res.message); loadDeadLeads(); });
  };

  if (propForm) {
    propForm.addEventListener('submit', function (e) {
      e.preventDefault();
      const fd = new FormData(propForm);
      fetch(`${BASE_URL}/api/properties/save.php`, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
          if (!res.success) { alert(res.message); return; }
          propModal.hide();
          loadDeadLeads();
        });
    });
  }

  if (tbody) loadDeadLeads();
})();
