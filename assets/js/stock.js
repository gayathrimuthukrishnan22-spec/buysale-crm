(function () {
  const BASE_URL = document.body.dataset.baseUrl || '';
  const stockType = window.STOCK_TYPE;
  const tbody = document.getElementById('stockTableBody');
  const pagination = document.getElementById('stockPagination');
  const cityStrip = document.getElementById('stockCityStrip');
  const cityFilter = document.getElementById('fCity');
  const stockForm = document.getElementById('stockForm');
  const stockModalEl = document.getElementById('stockModal');
  const stockModal = stockModalEl ? new bootstrap.Modal(stockModalEl) : null;

  let currentPage = 1;
  let debounceTimer = null;
  let activeCity = '';

  function escapeHtml(str) {
    return (str || '').toString().replace(/[&<>"']/g, m => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[m]));
  }
  function normalizeMobileForLink(mobile) {
    const digits = (mobile || '').replace(/[^0-9]/g, '');
    return digits.length === 10 ? '91' + digits : digits;
  }
  function quickActionsHtml(mobile, name, mapLink) {
    const pin = mapLink
      ? `<a href="${escapeHtml(mapLink)}" target="_blank" rel="noopener" class="btn-call" title="Open location on map"><i class="bi bi-geo-alt-fill"></i></a>`
      : '';
    if (!mobile) return pin || '<span class="text-muted-sm">-</span>';
    const tel = mobile.replace(/[^0-9+]/g, '');
    const wa = normalizeMobileForLink(mobile);
    const greeting = encodeURIComponent(`Hello ${name || ''}, this is regarding your ${window.STOCK_LABEL || 'property'} stock.`);
    return `
      <div class="quick-actions">
        <a href="tel:${tel}" class="btn-call" title="Call ${escapeHtml(name)}"><i class="bi bi-telephone-fill"></i></a>
        <a href="https://wa.me/${wa}?text=${greeting}" target="_blank" rel="noopener" class="btn-whatsapp" title="WhatsApp ${escapeHtml(name)}"><i class="bi bi-whatsapp"></i></a>
        ${pin}
      </div>`;
  }
  function fmtMoney(v) {
    if (v === null || v === undefined || v === '') return '-';
    return '₹' + Number(v).toLocaleString('en-IN');
  }

  function renderCityStrip(byCity) {
    if (!cityStrip) return;
    if (!byCity || !byCity.length) {
      cityStrip.innerHTML = '<span class="text-muted-sm">No stock found yet.</span>';
      return;
    }
    cityStrip.innerHTML = byCity.map(c => `
      <button type="button" class="btn btn-sm ${c.city === activeCity ? 'btn-accent' : 'btn-soft'} stock-city-chip" data-city="${escapeHtml(c.city === 'Not specified' ? '' : c.city)}">
        <i class="bi bi-geo-alt-fill me-1"></i>${escapeHtml(c.city)} <span class="badge bg-light text-dark ms-1">${c.total}</span>
      </button>
    `).join('');

    // Keep the Place dropdown filter in sync with what actually has stock.
    const current = cityFilter.value;
    const realCities = byCity.filter(c => c.city !== 'Not specified').map(c => c.city);
    cityFilter.innerHTML = '<option value="">All Places</option>' +
      realCities.map(c => `<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`).join('');
    cityFilter.value = realCities.includes(current) ? current : '';

    cityStrip.querySelectorAll('.stock-city-chip').forEach(btn => {
      btn.addEventListener('click', () => {
        activeCity = btn.dataset.city;
        cityFilter.value = activeCity;
        currentPage = 1;
        loadStock();
      });
    });
  }

  function renderRows(rows) {
    if (!rows.length) {
      tbody.innerHTML = `<tr><td colspan="9" class="text-center py-4 text-muted-sm">No stock found for this filter.</td></tr>`;
      return;
    }
    tbody.innerHTML = rows.map(r => `
      <tr>
        <td data-label="Property ID" class="fw-600">${escapeHtml(r.property_code)}</td>
        <td data-label="Client">
          <div class="client-name">${escapeHtml(r.client_name)}</div>
          <div class="client-mobile">${escapeHtml(r.client_mobile)}</div>
        </td>
        <td data-label="Type">${escapeHtml(r.property_type)}</td>
        <td data-label="Place">
          <div class="fw-600">${r.city ? escapeHtml(r.city) : '<span class="text-muted-sm">Not specified</span>'}</div>
          <div class="text-muted-sm fs-12">${escapeHtml(r.address)}</div>
        </td>
        <td data-label="Budget">${fmtMoney(r.budget)}</td>
        <td data-label="Assigned">${r.assigned_name ? escapeHtml(r.assigned_name) : '<span class="text-muted-sm">Unassigned</span>'}</td>
        <td data-label="Status"><span class="badge-status badge-${r.status}">${r.status}</span></td>
        <td data-label="Contact" class="text-center">${quickActionsHtml(r.client_mobile, r.client_name, r.map_link)}</td>
        <td data-label="Manage" class="text-end">
          <div class="dropdown">
            <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="#" onclick="viewStock(${r.id});return false;"><i class="bi bi-eye me-2"></i>View Details</a></li>
              <li><a class="dropdown-item" href="#" onclick="editStock(${r.id});return false;"><i class="bi bi-pencil me-2"></i>Edit</a></li>
              <li><a class="dropdown-item" href="#" onclick="openDescriptionBox('property', ${r.id}, '${escapeHtml(r.property_code)}');return false;"><i class="bi bi-card-text me-2"></i>Description</a></li>
              ${window.CAN_MANAGE_PROPERTIES ? `<li><a class="dropdown-item text-danger" href="#" onclick="deleteStock(${r.id});return false;"><i class="bi bi-trash me-2"></i>Delete</a></li>` : ''}
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
      html += `<li class="page-item ${i === page ? 'active' : ''}"><a class="page-link" href="#" onclick="stockGoToPage(${i});return false;">${i}</a></li>`;
    }
    pagination.innerHTML = html;
  }

  window.stockGoToPage = function (p) { currentPage = p; loadStock(); };

  function getFilters() {
    return {
      stock_type: stockType,
      search: document.getElementById('fSearch')?.value || '',
      status: document.getElementById('fStatus')?.value || '',
      city: cityFilter?.value || '',
      page: currentPage,
    };
  }

  function loadStock() {
    const params = new URLSearchParams(getFilters());
    tbody.innerHTML = `<tr><td colspan="9" class="text-center py-4 text-muted-sm">Loading...</td></tr>`;
    fetch(`${BASE_URL}/api/properties/stock.php?` + params.toString())
      .then(r => r.json())
      .then(res => {
        if (!res.success) throw new Error(res.message);
        renderRows(res.data.rows);
        renderPagination(res.data.total_pages, res.data.page);
        renderCityStrip(res.data.by_city);
      })
      .catch(err => {
        tbody.innerHTML = `<tr><td colspan="9" class="text-center py-4 text-danger">${escapeHtml(err.message)}</td></tr>`;
      });
  }

  ['fStatus'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', () => { currentPage = 1; loadStock(); });
  });
  if (cityFilter) cityFilter.addEventListener('change', () => { activeCity = cityFilter.value; currentPage = 1; loadStock(); });
  const searchEl = document.getElementById('fSearch');
  if (searchEl) searchEl.addEventListener('input', () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => { currentPage = 1; loadStock(); }, 400);
  });
  const clearBtn = document.getElementById('fClear');
  if (clearBtn) clearBtn.addEventListener('click', () => {
    document.getElementById('fSearch').value = '';
    document.getElementById('fStatus').value = 'active';
    cityFilter.value = '';
    activeCity = '';
    currentPage = 1;
    loadStock();
  });

  const personLabel = 'Stock';
  const mediaGrid = document.getElementById('stockMediaExisting');

  function mediaItemHtml(m) {
    let inner;
    if (m.media_type === 'video') {
      inner = `<video src="${BASE_URL}/uploads/${escapeHtml(m.file_path)}" muted></video>`;
    } else if (m.media_type === 'audio') {
      inner = `<i class="bi bi-file-earmark-music stock-media-audio-icon"></i>`;
    } else {
      inner = `<img src="${BASE_URL}/uploads/${escapeHtml(m.file_path)}" alt="">`;
    }
    return `
      <div class="stock-media-item" title="${escapeHtml(m.original_name || '')}">
        <a href="${BASE_URL}/uploads/${escapeHtml(m.file_path)}" target="_blank" rel="noopener">${inner}</a>
        <button type="button" class="stock-media-remove" title="Remove" onclick="removeStockMedia(${m.id})">
          <i class="bi bi-x"></i>
        </button>
      </div>`;
    // Note: the remove button above is hidden via CSS (.stock-media-view-only
    // .stock-media-remove { display:none }) when the grid's parent has the
    // stock-media-view-only class, which View Details sets.
  }

  function renderMediaGrid(images) {
    if (!mediaGrid) return;
    if (!images || !images.length) { mediaGrid.innerHTML = ''; return; }
    mediaGrid.innerHTML = images.map(mediaItemHtml).join('');
  }

  window.removeStockMedia = function (mediaId) {
    if (!confirm('Remove this file?')) return;
    const fd = new FormData();
    fd.append('media_id', mediaId);
    fd.append('csrf_token', stockForm.querySelector('[name=csrf_token]').value);
    fetch(`${BASE_URL}/api/properties/delete_media.php`, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(res => {
        if (!res.success) { alert(res.message); return; }
        const item = mediaGrid.querySelector(`[onclick="removeStockMedia(${mediaId})"]`)?.closest('.stock-media-item');
        if (item) item.remove();
      });
  };

  // Read-only mode for "View Details": disables every field, hides the file
  // pickers and the Save button, and hides the per-file remove (x) buttons
  // on the media grid so a view-only open can never change/delete data.
  function setStockFormReadOnly(readOnly) {
    stockForm.querySelectorAll('input, select, textarea').forEach(el => {
      if (el.type === 'hidden') return;
      el.disabled = readOnly;
      if (el.type === 'file') el.closest('.col-md-6, .col-md-4')?.classList.toggle('d-none', readOnly);
    });
    const saveBtn = stockModalEl.querySelector('.modal-footer button[type="submit"]');
    if (saveBtn) saveBtn.style.display = readOnly ? 'none' : '';
    const cancelBtn = stockModalEl.querySelector('.modal-footer button[data-bs-dismiss="modal"]');
    if (cancelBtn) cancelBtn.textContent = readOnly ? 'Close' : 'Cancel';
    mediaGrid?.classList.toggle('stock-media-view-only', readOnly);
  }

  window.openStockModal = function () {
    stockForm.reset();
    document.getElementById('stockId').value = '';
    document.getElementById('stockModalTitle').textContent = 'Add ' + personLabel;
    setStockFormReadOnly(false);
    renderMediaGrid([]);
  };

  function loadStockIntoForm(id, readOnly) {
    fetch(`${BASE_URL}/api/properties/detail.php?id=${id}`)
      .then(r => r.json())
      .then(res => {
        if (!res.success) { alert(res.message); return; }
        const p = res.data.property;
        stockForm.reset();
        document.getElementById('stockId').value = p.id;
        for (const key of ['client_name','client_mobile','property_type','address','city','map_link','budget','assigned_to','status','description','notes']) {
          const field = stockForm.querySelector(`[name="${key}"]`);
          if (field) field.value = p[key] ?? '';
        }
        renderMediaGrid(res.data.images);
        setStockFormReadOnly(readOnly);
        document.getElementById('stockModalTitle').textContent = (readOnly ? 'View ' : 'Edit ') + personLabel;
        stockModal.show();
      });
  }

  window.editStock = function (id) { loadStockIntoForm(id, false); };
  window.viewStock = function (id) { loadStockIntoForm(id, true); };

  window.deleteStock = function (id) {
    if (!confirm('Delete this stock entry? This cannot be undone.')) return;
    const fd = new FormData();
    fd.append('id', id);
    fd.append('csrf_token', stockForm.querySelector('[name=csrf_token]').value);
    fetch(`${BASE_URL}/api/properties/delete.php`, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(res => { alert(res.message); loadStock(); });
  };

  if (stockForm) {
    stockForm.addEventListener('submit', function (e) {
      e.preventDefault();
      const fd = new FormData(stockForm);
      fetch(`${BASE_URL}/api/properties/save.php`, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
          if (!res.success) { alert(res.message); return; }
          stockModal.hide();
          loadStock();
        });
    });
  }

  if (tbody) loadStock();
})();
