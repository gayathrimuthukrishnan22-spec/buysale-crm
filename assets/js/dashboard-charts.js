(function () {
  const BASE_URL = document.body.dataset.baseUrl || '';
  const CAN_REVENUE = !!window.CAN_VIEW_REVENUE_DASH;

  const palette = ['#4338ca', '#16a34a', '#d97706', '#dc2626', '#0369a1', '#be185d', '#6d28d9', '#0891b2'];

  function fmtMoneyShort(v) {
    if (v >= 10000000) return '₹' + (v / 10000000).toFixed(1) + 'Cr';
    if (v >= 100000) return '₹' + (v / 100000).toFixed(1) + 'L';
    if (v >= 1000) return '₹' + (v / 1000).toFixed(1) + 'K';
    return '₹' + v;
  }

  fetch(`${BASE_URL}/api/dashboard/chart_data.php`)
    .then(r => r.json())
    .then(res => {
      if (!res.success) { console.error(res.message); return; }
      const d = res.data;

      // ---- Revenue & Profit Trend (line) ----
      const revCanvas = document.getElementById('chartRevenueTrend');
      if (revCanvas && d.revenue_trend) {
        new Chart(revCanvas, {
          type: 'line',
          data: {
            labels: d.revenue_trend.labels,
            datasets: [
              { label: 'Revenue', data: d.revenue_trend.revenue, borderColor: '#4338ca', backgroundColor: 'rgba(67,56,202,.08)', fill: true, tension: 0.35 },
              { label: 'Profit', data: d.revenue_trend.profit, borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,.08)', fill: true, tension: 0.35 },
            ]
          },
          options: {
            responsive: true,
            plugins: { legend: { position: 'bottom' } },
            scales: { y: { ticks: { callback: v => fmtMoneyShort(v) } } }
          }
        });
      }

      // ---- Sales vs Buy vs Rental (doughnut) ----
      const catCanvas = document.getElementById('chartCategorySplit');
      if (catCanvas) {
        new Chart(catCanvas, {
          type: 'doughnut',
          data: {
            labels: ['Sale', 'Buy', 'Rental'],
            datasets: [{ data: [d.category_split.sale, d.category_split.buy, d.category_split.rental], backgroundColor: ['#d97706', '#16a34a', '#1d4ed8'] }]
          },
          options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });
      }

      // ---- Lead Conversion Funnel (horizontal bar) ----
      const funnelCanvas = document.getElementById('chartFunnel');
      if (funnelCanvas) {
        new Chart(funnelCanvas, {
          type: 'bar',
          data: { labels: d.funnel.labels, datasets: [{ label: 'Leads', data: d.funnel.data, backgroundColor: '#4338ca', borderRadius: 6 }] },
          options: { indexAxis: 'y', responsive: true, plugins: { legend: { display: false } } }
        });
      }

      // ---- Closed vs Cancelled vs Lost (doughnut) ----
      const outcomesCanvas = document.getElementById('chartOutcomes');
      if (outcomesCanvas) {
        new Chart(outcomesCanvas, {
          type: 'doughnut',
          data: {
            labels: ['Closed', 'Cancelled', 'Lost'],
            datasets: [{ data: [d.outcomes.deal_closed, d.outcomes.cancelled, d.outcomes.lost], backgroundColor: ['#16a34a', '#6b7280', '#dc2626'] }]
          },
          options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });
      }

      // ---- Profit vs Loss (this month) ----
      const plCanvas = document.getElementById('chartProfitLoss');
      if (plCanvas && d.profit_vs_loss) {
        new Chart(plCanvas, {
          type: 'bar',
          data: { labels: ['This Month'], datasets: [
            { label: 'Profit', data: [d.profit_vs_loss.profit], backgroundColor: '#16a34a', borderRadius: 6 },
            { label: 'Loss', data: [d.profit_vs_loss.loss], backgroundColor: '#dc2626', borderRadius: 6 },
          ] },
          options: { responsive: true, plugins: { legend: { position: 'bottom' } }, scales: { y: { ticks: { callback: v => fmtMoneyShort(v) } } } }
        });
      }

      // ---- Lead Source Analysis (used in either slot depending on layout) ----
      ['chartLeadSource', 'chartLeadSource2'].forEach(id => {
        const canvas = document.getElementById(id);
        if (canvas && d.lead_source.labels.length) {
          new Chart(canvas, {
            type: 'pie',
            data: { labels: d.lead_source.labels, datasets: [{ data: d.lead_source.data, backgroundColor: palette }] },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
          });
        }
      });
    })
    .catch(err => console.error('Dashboard chart load failed:', err));
})();
