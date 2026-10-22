(function () {
  const BASE_URL = document.body.dataset.baseUrl || window.APP_BASE_URL || '';
  const tbody = document.getElementById('leadsTableBody');
  const pagination = document.getElementById('leadsPagination');
  const leadForm = document.getElementById('leadForm');
  const leadModalEl = document.getElementById('leadModal');
  const leadModal = leadModalEl ? new bootstrap.Modal(leadModalEl) : null;
  const CAN_VIEW_REVENUE = !!window.CAN_VIEW_REVENUE;

  let currentPage = 1;
  let debounceTimer = null;

  const statusLabel = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

  function normalizeMobileForLink(mobile) {
    const digits = (mobile || '').replace(/[^0-9]/g, '');
    return digits.length === 10 ? '91' + digits : digits;
  }

  function quickActionsHtml(mobile, name, requirement) {
    if (!mobile) return '<span class="text-muted-sm">-</span>';
    const tel = mobile.replace(/[^0-9+]/g, '');
    const wa = normalizeMobileForLink(mobile);
    const greeting = encodeURIComponent(`Hello ${name || ''}, this is regarding your ${requirement || 'property'} requirement.`);
    return `
      <div class="quick-actions">
        <a href="tel:${tel}" class="btn-call" title="Call ${escapeHtml(name)}"><i class="bi bi-telephone-fill"></i></a>
        <a href="https://wa.me/${wa}?text=${greeting}" target="_blank" rel="noopener" class="btn-whatsapp" title="WhatsApp ${escapeHtml(name)}"><i class="bi bi-whatsapp"></i></a>
      </div>`;
  }

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

  const colCount = CAN_VIEW_REVENUE ? 12 : 11;

  function renderRows(rows) {
    if (!rows.length) {
      tbody.innerHTML = `<tr><td colspan="${colCount}" class="text-center py-4 text-muted-sm">No leads found.</td></tr>`;
      return;
    }
    tbody.innerHTML = rows.map(r => `
      <tr>
        <td data-label="Lead ID" class="fw-600">${escapeHtml(r.lead_code)}</td>
        <td data-label="Client">
          <div class="client-name">${escapeHtml(r.client_name)}</div>
          <div class="client-mobile">${escapeHtml(r.client_mobile)}</div>
        </td>
        <td data-label="Location">${escapeHtml(r.preferred_location || r.city || '-')}</td>
        <td data-label="Requirement" class="text-capitalize">${escapeHtml(r.requirement)}${r.property_type ? '<br><span class="text-muted-sm">' + escapeHtml(r.property_type) + '</span>' : ''}</td>
        <td data-label="Budget">${fmtMoney(r.budget)}</td>
        ${CAN_VIEW_REVENUE ? `<td data-label="Revenue">${r.status === 'deal_closed' ? fmtMoney(r.actual_revenue) : (r.expected_revenue ? fmtMoney(r.expected_revenue) + ' <span class="text-muted-sm">(exp.)</span>' : '-')}</td>` : ''}
        <td data-label="Assigned To">${r.assigned_name ? escapeHtml(r.assigned_name) : '<span class="text-muted-sm">Unassigned</span>'}</td>
        <td data-label="Status"><span class="badge-status badge-${r.status}">${statusLabel(r.status)}</span></td>
        <td data-label="Next Follow-up">${r.next_followup_at ? fmtDate(r.next_followup_at) : '<span class="text-muted-sm">-</span>'}</td>
        <td data-label="Created" class="text-muted-sm">${fmtDate(r.created_at)}</td>
        <td data-label="Details" class="text-center">
          <button type="button" class="btn btn-sm btn-outline-accent" onclick="viewLeadDetail(${r.id});"><i class="bi bi-eye me-1"></i>View Detail</button>
        </td>
        <td data-label="Contact" class="text-center">${quickActionsHtml(r.client_mobile, r.whatsapp_number || r.client_mobile, r.requirement)}</td>
        <td data-label="Manage" class="text-end">
          <div class="dropdown">
            <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="#" onclick="viewLeadDetail(${r.id});return false;"><i class="bi bi-eye me-2"></i>View Details</a></li>
              <li><a class="dropdown-item" href="#" onclick="editLead(${r.id});return false;"><i class="bi bi-pencil me-2"></i>Edit</a></li>
              <li><a class="dropdown-item" href="#" onclick="openDescriptionBox('lead', ${r.id}, '${escapeHtml(r.lead_code)}');return false;"><i class="bi bi-card-text me-2"></i>Description</a></li>
              <li><a class="dropdown-item" href="#" onclick="viewHistory(${r.id});return false;"><i class="bi bi-clock-history me-2"></i>History</a></li>
              ${r.property_id && r.requirement !== 'sale' ? `<li><a class="dropdown-item" href="#" onclick="findMatches(${r.property_id});return false;"><i class="bi bi-stars me-2"></i>Find Matching Stock</a></li>` : ''}
              ${window.CAN_DELETE_LEADS ? `<li><a class="dropdown-item text-danger" href="#" onclick="deleteLead(${r.id});return false;"><i class="bi bi-trash me-2"></i>Delete</a></li>` : ''}
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
      html += `<li class="page-item ${i === page ? 'active' : ''}"><a class="page-link" href="#" onclick="goToPage(${i});return false;">${i}</a></li>`;
    }
    pagination.innerHTML = html;
  }

  window.goToPage = function (p) { currentPage = p; loadLeads(); };

  function getFilters() {
    return {
      search: document.getElementById('fSearch')?.value || '',
      status: document.getElementById('fStatus')?.value || '',
      requirement: document.getElementById('fRequirement')?.value || '',
      employee: document.getElementById('fEmployee')?.value || '',
      date: document.getElementById('fDate')?.value || '',
      page: currentPage,
    };
  }

  function loadLeads() {
    const params = new URLSearchParams(getFilters());
    tbody.innerHTML = `<tr><td colspan="${colCount}" class="text-center py-4 text-muted-sm">Loading...</td></tr>`;
    fetch(`${BASE_URL}/api/leads/list.php?` + params.toString())
      .then(r => r.json())
      .then(res => {
        if (!res.success) throw new Error(res.message);
        renderRows(res.data.rows);
        renderPagination(res.data.total_pages, res.data.page);
      })
      .catch(err => {
        tbody.innerHTML = `<tr><td colspan="${colCount}" class="text-center py-4 text-danger">${escapeHtml(err.message || 'Failed to load leads.')}</td></tr>`;
      });
  }

  ['fStatus', 'fRequirement', 'fEmployee', 'fDate'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', () => { currentPage = 1; loadLeads(); });
  });
  const searchEl = document.getElementById('fSearch');
  if (searchEl) searchEl.addEventListener('input', () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => { currentPage = 1; loadLeads(); }, 400);
  });

  // ---------------- Status-driven section toggling (Deal Closure / Cancelled Reason) ----------------
  const leadStatusEl = document.getElementById('leadStatus');
  const leadRequirementEl = document.getElementById('leadRequirement');
  const dealSection = document.getElementById('dealClosureSection');
  const cancelledSection = document.getElementById('cancelledReasonSection');
  const rentalOnlyFields = document.querySelectorAll('.rental-only');
  const propertyValueField = document.getElementById('propertyValueField');

  function refreshSections() {
    if (!leadStatusEl) return;
    const status = leadStatusEl.value;
    if (dealSection) dealSection.classList.toggle('d-none', status !== 'deal_closed');
    if (cancelledSection) cancelledSection.classList.toggle('d-none', !(status === 'cancelled' || status === 'lost'));

    const isRental = leadRequirementEl && leadRequirementEl.value === 'rental';
    rentalOnlyFields.forEach(el => el.classList.toggle('d-none', !isRental));
    if (propertyValueField) propertyValueField.classList.toggle('d-none', isRental);
    updateRevenuePreview();
  }

  function updateRevenuePreview() {
    const isRental = leadRequirementEl && leadRequirementEl.value === 'rental';
    const base = isRental
      ? parseFloat(document.getElementById('monthlyRent')?.value || 0)
      : parseFloat(document.getElementById('propertyValue')?.value || 0);
    const rate = parseFloat(document.getElementById('commissionRate')?.value || 0);
    const incentive = parseFloat(document.getElementById('employeeIncentive')?.value || 0);

    const commission = base * (rate / 100);
    const revenue = commission;
    const profit = revenue - incentive;

    const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = '₹' + (isNaN(val) ? 0 : val).toLocaleString('en-IN', { maximumFractionDigits: 0 }); };
    set('previewCommission', commission);
    set('previewRevenue', revenue);
    set('previewProfit', profit);
  }

  if (leadStatusEl) leadStatusEl.addEventListener('change', refreshSections);
  if (leadRequirementEl) leadRequirementEl.addEventListener('change', refreshSections);
  ['propertyValue', 'commissionRate', 'employeeIncentive', 'monthlyRent'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', updateRevenuePreview);
  });

  // ---------------- Requirement Category -> Property Type field switching ----------------
  // house_rent_requirement / flat_lease / flat_selling -> BHK dropdown
  // commercial_space_requirement -> Sqft input
  // land_selling / anything else -> plain text (as before)
  const leadCustomerTypeEl = document.getElementById('leadCustomerType');
  const ptDefaultGroup = document.getElementById('ptDefaultGroup');
  const ptBhkGroup = document.getElementById('ptBhkGroup');
  const ptSqftGroup = document.getElementById('ptSqftGroup');
  const propertyTypeInput = document.getElementById('propertyTypeInput');
  const bhkSelect = document.getElementById('bhkSelect');
  const sqftInput = document.getElementById('sqftInput');
  const BHK_CATEGORIES = ['house_rent_requirement', 'flat_lease', 'flat_selling'];

  function refreshPropertyTypeField() {
    if (!leadCustomerTypeEl) return;
    const val = leadCustomerTypeEl.value;
    const isBhk = BHK_CATEGORIES.includes(val);
    const isSqft = val === 'commercial_space_requirement';
    if (ptDefaultGroup) ptDefaultGroup.classList.toggle('d-none', isBhk || isSqft);
    if (ptBhkGroup) ptBhkGroup.classList.toggle('d-none', !isBhk);
    if (ptSqftGroup) ptSqftGroup.classList.toggle('d-none', !isSqft);
  }

  function syncPropertyTypeValue() {
    if (!leadCustomerTypeEl || !propertyTypeInput) return;
    const val = leadCustomerTypeEl.value;
    if (BHK_CATEGORIES.includes(val)) {
      propertyTypeInput.value = bhkSelect ? bhkSelect.value : '';
    } else if (val === 'commercial_space_requirement') {
      propertyTypeInput.value = sqftInput && sqftInput.value ? sqftInput.value + ' sqft' : '';
    }
  }

  // Prefill the BHK/Sqft widget from an existing property_type value (edit mode)
  function prefillPropertyTypeWidget(value) {
    const val = leadCustomerTypeEl ? leadCustomerTypeEl.value : '';
    if (BHK_CATEGORIES.includes(val) && bhkSelect) {
      bhkSelect.value = value || '';
    } else if (val === 'commercial_space_requirement' && sqftInput) {
      const match = (value || '').match(/[\d.]+/);
      sqftInput.value = match ? match[0] : '';
    }
  }

  if (leadCustomerTypeEl) leadCustomerTypeEl.addEventListener('change', () => { refreshPropertyTypeField(); syncPropertyTypeValue(); refreshProjectOptions(); });
  if (bhkSelect) bhkSelect.addEventListener('change', syncPropertyTypeValue);
  if (sqftInput) sqftInput.addEventListener('input', syncPropertyTypeValue);

  // ---------------- Requirement Category -> Project dropdown filtering ----------------
  // Only show projects tagged with the matching category (or 'general', which always shows).
  // The project currently selected (e.g. when opening Edit) stays visible even if its
  // category doesn't match, so existing links aren't hidden from view.
  const leadProjectSelect = document.getElementById('leadProjectSelect');

  function refreshProjectOptions() {
    if (!leadProjectSelect || !leadCustomerTypeEl) return;
    const val = leadCustomerTypeEl.value;
    const currentSelected = leadProjectSelect.value;
    Array.from(leadProjectSelect.options).forEach(opt => {
      if (!opt.value) return; // "— None —" always visible
      const cat = opt.dataset.category || 'general';
      const match = (cat === 'general' || cat === val || opt.value === currentSelected);
      opt.hidden = !match;
      opt.disabled = !match;
    });
  }

  window.openLeadModal = function () {
    leadForm.reset();
    document.getElementById('leadId').value = '';
    document.getElementById('leadModalTitle').textContent = 'Add Lead';
    refreshSections();
    refreshPropertyTypeField();
    syncPropertyTypeValue();
    refreshProjectOptions();
  };

  window.editLead = function (id) {
    fetch(`${BASE_URL}/api/leads/detail.php?id=${id}`)
      .then(r => r.json())
      .then(res => {
        if (!res.success) { alert(res.message); return; }
        const l = res.data.lead;
        leadForm.reset();
        document.getElementById('leadId').value = l.id;
        const fields = ['client_name','client_mobile','whatsapp_number','client_email','address','city','state',
          'country','customer_type','requirement','property_type','budget','preferred_location','project_id',
          'assigned_to','status','source','notes','property_value','commission_rate','employee_incentive',
          'monthly_rent','security_deposit','lease_duration_months','owner_name','owner_mobile',
          'cancelled_reason','cancelled_reason_notes'];
        for (const key of fields) {
          const field = leadForm.querySelector(`[name="${key}"]`);
          if (field) field.value = l[key] ?? '';
        }
        document.getElementById('leadModalTitle').textContent = 'Edit Lead';
        refreshSections();
        refreshPropertyTypeField();
        prefillPropertyTypeWidget(l.property_type);
        refreshProjectOptions();
        leadModal.show();
      });
  };

  window.deleteLead = function (id) {
    if (!confirm('Delete this lead? This cannot be undone.')) return;
    const fd = new FormData();
    fd.append('id', id);
    fd.append('csrf_token', leadForm.querySelector('[name=csrf_token]').value);
    fetch(`${BASE_URL}/api/leads/delete.php`, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(res => { alert(res.message); loadLeads(); });
  };

  window.viewHistory = function (id) {
    window.location.href = `${BASE_URL}/admin/lead-history.php?id=${id}`;
  };

  // ---------------- Find Matching Stock (reuses the same api/properties/ endpoints
  // that the Properties module uses -- every lead is auto-synced to a linked
  // "client requirement" row in `properties` via lead.property_id, so matching
  // against "Our Stock" works exactly the same way from here). ----------------
  const SUBSCRIPTION_AMOUNT_DISPLAY = window.SUBSCRIPTION_AMOUNT || 1000;
  const SUBSCRIPTION_MATCH_LIMIT = window.SUBSCRIPTION_MATCH_LIMIT || 2;
  const matchModalEl = document.getElementById('matchModal');
  const matchModal = matchModalEl ? new bootstrap.Modal(matchModalEl) : null;
  const matchModalBody = document.getElementById('matchModalBody');
  let currentMatchClientId = null;

  function scoreBadgeClass(score) {
    if (score >= 70) return 'badge-active';
    if (score >= 40) return 'badge-pending';
    return 'badge-cancelled';
  }

  function getCsrfToken() {
    return leadForm ? leadForm.querySelector('[name=csrf_token]').value : '';
  }

  window.findMatches = function (propertyId) {
    if (!matchModal) return;
    currentMatchClientId = propertyId;
    matchModalBody.innerHTML = `<div class="text-center py-4 text-muted-sm">Loading...</div>`;
    matchModal.show();
    fetch(`${BASE_URL}/api/properties/match_stock.php?id=${propertyId}`)
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
          if (subscription.paid === false) {
            html += `<div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
              <div><i class="bi bi-exclamation-circle me-1"></i>Subscription not paid yet for this lead.</div>
              <button type="button" class="btn btn-sm btn-accent flex-shrink-0" onclick="markSubscriptionPaid(${client.id})"><i class="bi bi-cash-stack me-1"></i>Mark Subscription Paid (₹${SUBSCRIPTION_AMOUNT_DISPLAY})</button>
            </div>`;
          } else if (subscription.exhausted) {
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
        } else if (!note && !(subscription && subscription.paid === false)) {
          html += `<div class="text-center py-4 text-muted-sm">No matching stock found right now.</div>`;
        }
        matchModalBody.innerHTML = html;
      })
      .catch(err => {
        matchModalBody.innerHTML = `<div class="text-danger">${escapeHtml(err.message)}</div>`;
      });
  };

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
          loadLeads();
          if (currentMatchClientId === clientId) window.findMatches(clientId);
        }
      });
  };

  window.markSubscriptionPaid = function (id) {
    if (!confirm(`Confirm ₹${SUBSCRIPTION_AMOUNT_DISPLAY} subscription payment has been collected for this lead? This enables Find Matching Stock.`)) return;
    const fd = new FormData();
    fd.append('client_id', id);
    fd.append('csrf_token', getCsrfToken());
    fetch(`${BASE_URL}/api/properties/mark_subscription_paid.php`, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(res => {
        alert(res.message);
        if (res.success) window.findMatches(id);
      });
  };

  window.renewSubscription = function (id, reopenMatches) {
    if (!confirm(`Renew subscription for ₹${SUBSCRIPTION_AMOUNT_DISPLAY}? This gives ${SUBSCRIPTION_MATCH_LIMIT} new matches and reactivates the lead if it was marked dead.`)) return;
    const fd = new FormData();
    fd.append('client_id', id);
    fd.append('csrf_token', getCsrfToken());
    fetch(`${BASE_URL}/api/properties/renew_subscription.php`, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(res => {
        alert(res.message);
        if (res.success && (reopenMatches || currentMatchClientId === id)) window.findMatches(id);
      });
  };

  if (leadForm) {
    leadForm.addEventListener('submit', function (e) {
      e.preventDefault();
      syncPropertyTypeValue();
      const fd = new FormData(leadForm);
      fetch(`${BASE_URL}/api/leads/save.php`, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
          if (!res.success) { alert(res.message); return; }
          leadModal.hide();
          loadLeads();
        });
    });
  }

  if (tbody) loadLeads();
})();
