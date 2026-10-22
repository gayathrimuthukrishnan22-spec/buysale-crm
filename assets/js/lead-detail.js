(function () {
  const BASE_URL = document.body.dataset.baseUrl || window.APP_BASE_URL || '';
  const modalEl = document.getElementById('leadDetailModal');
  if (!modalEl) return;
  const modal = new bootstrap.Modal(modalEl);
  const dots = Array.from(document.querySelectorAll('#ldDots .lead-step-dot'));
  const stepBody = document.getElementById('ldStepBody');
  const stepCount = document.getElementById('ldStepCount');
  const backBtn = document.getElementById('ldBackBtn');
  const nextBtn = document.getElementById('ldNextBtn');
  const refLabel = document.getElementById('ldRef');

  const STEP_LABELS = ['Personal Details', 'Identity & Address', 'Property Requirement', 'Document', 'Payment'];
  let currentLead = null;
  let currentDocs = [];
  let currentPayments = [];
  let currentPropertyPhotos = [];
  let step = 0;

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
    return new Date(v.replace(' ', 'T')).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
  }
  function row(label, value) {
    return `<div class="ld-row"><span class="ld-label">${escapeHtml(label)}</span><span class="ld-value">${value === null || value === undefined || value === '' ? '<span class="text-muted-sm">-</span>' : escapeHtml(String(value))}</span></div>`;
  }
  function titleCase(s) {
    return (s || '').toString().replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
  }

  function renderPersonal(l) {
    return row('Full Name', l.client_name) +
      row('Mobile Number', l.client_mobile) +
      row('WhatsApp Number', l.whatsapp_number) +
      row('Email', l.client_email) +
      row('Date of Birth', l.dob ? fmtDate(l.dob) : null) +
      row('Gender', l.gender) +
      row('Occupation', l.occupation);
  }

  function renderIdentity(l) {
    return row('Aadhaar Number', l.aadhaar_number) +
      row('PAN Number', l.pan_number) +
      row('Religion', l.religion) +
      row('Caste', l.caste) +
      row('Address', l.address) +
      row('City', l.city) +
      row('District', l.district) +
      row('State', l.state) +
      row('Pincode', l.pincode) +
      row('Country', l.country);
  }

  function renderRequirement(l, photos) {
    let html = row('Requirement', titleCase(l.requirement)) +
      row('Property Type', l.property_type) +
      row('Budget', l.budget ? fmtMoney(l.budget) : null) +
      row('Preferred Location', l.preferred_location);
    let details = {};
    try { details = l.requirement_details ? JSON.parse(l.requirement_details) : {}; } catch (e) { details = {}; }
    const flat = {};
    Object.keys(details).forEach(k => {
      const v = details[k];
      if (v && typeof v === 'object') {
        Object.keys(v).forEach(k2 => { flat[k2] = v[k2]; });
      } else if (v !== null && v !== '') {
        flat[k] = v;
      }
    });
    Object.keys(flat).forEach(k => {
      html += row(titleCase(k), flat[k]);
    });

    // Photos/video the customer uploaded via the "Give my own Property" flow
    // (book.html Step 4) — only present for leads that came in that way.
    if (photos && photos.length) {
      html += '<div class="ld-photo-section-title">Property Photos</div>';
      html += '<div class="ld-photo-grid">' + photos.map(p => {
        const src = `${BASE_URL}/uploads/${p.file_path}`;
        const inner = p.media_type === 'video'
          ? `<video src="${src}" muted></video>`
          : `<img src="${src}" alt="${escapeHtml(p.original_name || 'Property photo')}" loading="lazy">`;
        return `<a href="${src}" target="_blank" rel="noopener">${inner}</a>`;
      }).join('') + '</div>';
    }

    return html;
  }

  function renderDocuments(docs) {
    if (!docs.length) return '<div class="ld-empty">No documents uploaded.</div>';
    return docs.map(d => `
      <div class="ld-doc-card">
        <div>
          <div class="fw-600">${escapeHtml(titleCase(d.doc_type))}</div>
          <div class="text-muted-sm">${escapeHtml(d.original_name || '')}</div>
        </div>
        <a class="btn btn-sm btn-outline-accent" href="${BASE_URL}/uploads/${d.file_path}" target="_blank" rel="noopener">
          <i class="bi bi-eye me-1"></i>View
        </a>
      </div>`).join('');
  }

  function renderPayments(payments) {
    if (!payments.length) return '<div class="ld-empty">No payment recorded.</div>';
    return payments.map(p => `
      <div class="ld-pay-card">
        <div>
          <div class="fw-600">${fmtMoney(p.amount)} <span class="badge-status badge-${p.status === 'verified' ? 'deal_closed' : (p.status === 'rejected' ? 'cancelled' : 'new')}">${escapeHtml(titleCase(p.status))}</span></div>
          <div class="text-muted-sm">${escapeHtml(p.method || '')} &middot; ${fmtDate(p.paid_at)}</div>
        </div>
        ${p.screenshot_path ? `<a class="btn btn-sm btn-outline-accent" href="${BASE_URL}/uploads/${p.screenshot_path}" target="_blank" rel="noopener"><i class="bi bi-eye me-1"></i>View Proof</a>` : ''}
      </div>`).join('');
  }

  function renderStep() {
    if (!currentLead) return;
    dots.forEach((d, i) => {
      d.classList.toggle('active', i === step);
      d.classList.toggle('done', i < step);
    });
    stepCount.textContent = `${step + 1} / ${STEP_LABELS.length}`;
    backBtn.disabled = step === 0;
    nextBtn.innerHTML = step === STEP_LABELS.length - 1
      ? 'Close'
      : 'Next<i class="bi bi-arrow-right ms-1"></i>';

    let html = '';
    if (step === 0) html = renderPersonal(currentLead);
    else if (step === 1) html = renderIdentity(currentLead);
    else if (step === 2) html = renderRequirement(currentLead, currentPropertyPhotos);
    else if (step === 3) html = renderDocuments(currentDocs);
    else if (step === 4) html = renderPayments(currentPayments);
    stepBody.innerHTML = html;
  }

  dots.forEach(d => {
    d.addEventListener('click', () => {
      step = parseInt(d.dataset.step, 10);
      renderStep();
    });
  });
  backBtn.addEventListener('click', () => {
    if (step > 0) { step--; renderStep(); }
  });
  nextBtn.addEventListener('click', () => {
    if (step < STEP_LABELS.length - 1) { step++; renderStep(); }
    else modal.hide();
  });

  window.viewLeadDetail = function (id) {
    fetch(`${BASE_URL}/api/leads/detail.php?id=${id}`)
      .then(r => r.json())
      .then(res => {
        if (!res.success) { alert(res.message); return; }
        currentLead = res.data.lead;
        currentDocs = res.data.documents || [];
        currentPayments = res.data.payments || [];
        currentPropertyPhotos = res.data.propertyPhotos || [];
        refLabel.textContent = currentLead.lead_code ? `(${currentLead.lead_code})` : '';
        step = 0;
        renderStep();
        modal.show();
      });
  };
})();
