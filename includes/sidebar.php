<?php
$current = basename($_SERVER['PHP_SELF']);
function navActive($file, $current) { return $file === $current ? 'active' : ''; }
$root = usesAdminLayout() ? BASE_URL . '/admin' : BASE_URL . '/employee';
?>
<aside class="app-sidebar" id="appSidebar">
  <div class="sidebar-scroll">
    <ul class="nav flex-column sidebar-nav">
      <li class="nav-item">
        <a class="nav-link <?= navActive('dashboard.php', $current) ?>" href="<?= $root ?>/dashboard.php">
          <i class="bi bi-speedometer2"></i> Dashboard
        </a>
      </li>
      <?php if (userCan('leads.view')): ?>
      <li class="nav-item">
        <a class="nav-link <?= navActive('leads.php', $current) ?>" href="<?= $root ?>/leads.php">
          <i class="bi bi-person-lines-fill"></i> Leads
        </a>
      </li>
      <?php endif; ?>
      <?php if (userCan('leads.view_all')): ?>
      <li class="nav-item">
        <a class="nav-link <?= navActive('payment-verification.php', $current) ?>" href="<?= BASE_URL ?>/admin/payment-verification.php">
          <i class="bi bi-shield-check"></i> Payment Verification
        </a>
      </li>
      <?php endif; ?>
      <?php if (userCan('properties.view')): ?>
      <li class="sidebar-section">Properties</li>
      <li class="nav-item">
        <a class="nav-link <?= navActive('properties-buy.php', $current) ?>" href="<?= $root ?>/properties-buy.php">
          <i class="bi bi-cash-coin"></i> Buy
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= navActive('properties-rental.php', $current) ?>" href="<?= $root ?>/properties-rental.php">
          <i class="bi bi-key"></i> Rental Property
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= navActive('properties-rental-tenant.php', $current) ?>" href="<?= $root ?>/properties-rental-tenant.php">
          <i class="bi bi-house-gear"></i> Tenant Rental
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= navActive('properties-lease.php', $current) ?>" href="<?= $root ?>/properties-lease.php">
          <i class="bi bi-file-earmark-text"></i> Lease
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?= navActive('dead-leads.php', $current) ?>" href="<?= $root ?>/dead-leads.php">
          <i class="bi bi-emoji-frown"></i> Dead Leads
        </a>
      </li>
      <?php endif; ?>
      <?php
      $stockLeasePages = ['stock-lease.php', 'stock-lease-residential.php'];
      $stockPages = array_merge(['stock-rental.php','stock-land.php','stock-agri-land.php','stock-house.php','stock-commercial.php'], $stockLeasePages);
      $stockOpen = in_array($current, $stockPages, true);
      $stockLeaseOpen = in_array($current, $stockLeasePages, true);
      ?>
      <?php if (userCan('properties.view')): ?>
      <li class="nav-item">
        <a class="nav-link sidebar-toggle <?= $stockOpen ? 'active' : '' ?>" href="#" data-bs-toggle="collapse"
           data-bs-target="#ourServiceMenu" aria-expanded="<?= $stockOpen ? 'true' : 'false' ?>">
          <i class="bi bi-boxes"></i> Our Stock
          <i class="bi bi-chevron-down ms-auto sidebar-toggle-caret"></i>
        </a>
        <div class="collapse <?= $stockOpen ? 'show' : '' ?>" id="ourServiceMenu">
          <ul class="nav flex-column sidebar-submenu">
            <li class="nav-item">
              <a class="nav-link <?= navActive('stock-rental.php', $current) ?>" href="<?= $root ?>/stock-rental.php">
                <i class="bi bi-key"></i> Rental House
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link <?= navActive('stock-land.php', $current) ?>" href="<?= $root ?>/stock-land.php">
                <i class="bi bi-map"></i> Land for Sale
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link <?= navActive('stock-agri-land.php', $current) ?>" href="<?= $root ?>/stock-agri-land.php">
                <i class="bi bi-tree"></i> Agriculture Land
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link <?= navActive('stock-house.php', $current) ?>" href="<?= $root ?>/stock-house.php">
                <i class="bi bi-house-door"></i> House for Sale
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link <?= navActive('stock-commercial.php', $current) ?>" href="<?= $root ?>/stock-commercial.php">
                <i class="bi bi-building"></i> Commercial
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link sidebar-toggle <?= $stockLeaseOpen ? 'active' : '' ?>" href="#" data-bs-toggle="collapse"
                 data-bs-target="#stockLeaseMenu" aria-expanded="<?= $stockLeaseOpen ? 'true' : 'false' ?>">
                <i class="bi bi-file-earmark-text"></i> Lease
                <i class="bi bi-chevron-down ms-auto sidebar-toggle-caret"></i>
              </a>
              <div class="collapse <?= $stockLeaseOpen ? 'show' : '' ?>" id="stockLeaseMenu">
                <ul class="nav flex-column sidebar-submenu">
                  <li class="nav-item">
                    <a class="nav-link <?= navActive('stock-lease.php', $current) ?>" href="<?= $root ?>/stock-lease.php">
                      <i class="bi bi-building"></i> Commercial
                    </a>
                  </li>
                  <li class="nav-item">
                    <a class="nav-link <?= navActive('stock-lease-residential.php', $current) ?>" href="<?= $root ?>/stock-lease-residential.php">
                      <i class="bi bi-house-heart"></i> Residential
                    </a>
                  </li>
                </ul>
              </div>
            </li>
          </ul>
        </div>
      </li>
      <?php endif; ?>
      <?php if (userCan('projects.view')): ?>
      <li class="nav-item">
        <a class="nav-link <?= navActive('projects.php', $current) ?>" href="<?= BASE_URL ?>/admin/projects.php">
          <i class="bi bi-buildings"></i> Projects
        </a>
      </li>
      <?php endif; ?>
      <?php if (userCan('followups.manage')): ?>
      <li class="nav-item">
        <a class="nav-link <?= navActive('followups.php', $current) ?>" href="<?= $root ?>/followups.php">
          <i class="bi bi-calendar-check"></i> Meetings &amp; Follow-ups
        </a>
      </li>
      <?php endif; ?>
      <?php if (userCan('employees.manage') || userCan('reports.view') || userCan('audit.view')): ?>
      <li class="sidebar-section">Admin</li>
      <?php endif; ?>
      <?php if (userCan('employees.manage')): ?>
      <li class="nav-item">
        <a class="nav-link <?= navActive('employees.php', $current) ?>" href="<?= BASE_URL ?>/admin/employees.php">
          <i class="bi bi-people"></i> Employees
        </a>
      </li>
      <?php endif; ?>
      <?php if (userCan('reports.view')): ?>
      <li class="nav-item">
        <a class="nav-link <?= navActive('reports.php', $current) ?>" href="<?= BASE_URL ?>/admin/reports.php">
          <i class="bi bi-bar-chart-line"></i> Reports
        </a>
      </li>
      <?php endif; ?>
      <?php if (userCan('audit.view')): ?>
      <li class="nav-item">
        <a class="nav-link <?= navActive('audit-log.php', $current) ?>" href="<?= BASE_URL ?>/admin/audit-log.php">
          <i class="bi bi-clock-history"></i> Audit Log
        </a>
      </li>
      <?php endif; ?>
    </ul>
  </div>
</aside>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<nav class="bottom-nav d-lg-none">
  <a class="bottom-nav-link <?= navActive('dashboard.php', $current) ?>" title="Dashboard" href="<?= $root ?>/dashboard.php">
    <i class="bi bi-speedometer2"></i><span class="bottom-nav-label">Dashboard</span>
  </a>
  
  <?php if (userCan('leads.view')): ?>
  <a class="bottom-nav-link <?= navActive('leads.php', $current) ?>" title="Leads" href="<?= $root ?>/leads.php">
    <i class="bi bi-person-lines-fill"></i><span class="bottom-nav-label">Leads</span>
  </a>
  
  <?php endif; ?>
  <?php if (userCan('properties.view')): ?>
  <a class="bottom-nav-link <?= navActive('properties-buy.php', $current) ?>" title="Buy" href="<?= $root ?>/properties-buy.php">
    <i class="bi bi-cash-coin"></i><span class="bottom-nav-label">Buy</span>
  </a>
  
  <a class="bottom-nav-link <?= navActive('properties-rental.php', $current) ?>" title="Rental" href="<?= $root ?>/properties-rental.php">
    <i class="bi bi-key"></i><span class="bottom-nav-label">Rental</span>
  </a>
  
  <a class="bottom-nav-link <?= navActive('properties-lease.php', $current) ?>" title="Lease" href="<?= $root ?>/properties-lease.php">
    <i class="bi bi-file-earmark-text"></i><span class="bottom-nav-label">Lease</span>
  </a>
  
  <?php endif; ?>
  <?php if (userCan('projects.view')): ?>
  <a class="bottom-nav-link <?= navActive('projects.php', $current) ?>" title="Projects" href="<?= BASE_URL ?>/admin/projects.php">
    <i class="bi bi-buildings"></i><span class="bottom-nav-label">Projects</span>
  </a>
  
  <?php endif; ?>
  <?php if (userCan('followups.manage')): ?>
  <a class="bottom-nav-link <?= navActive('followups.php', $current) ?>" title="Follow-ups" href="<?= $root ?>/followups.php">
    <i class="bi bi-calendar-check"></i><span class="bottom-nav-label">Follow-ups</span>
  </a>
  
  <?php endif; ?>
  <?php if (userCan('employees.manage')): ?>
  <a class="bottom-nav-link <?= navActive('employees.php', $current) ?>" title="Employees" href="<?= BASE_URL ?>/admin/employees.php">
    <i class="bi bi-people"></i><span class="bottom-nav-label">Employees</span>
  </a>
  
  <?php endif; ?>
  <?php if (userCan('reports.view')): ?>
  <a class="bottom-nav-link <?= navActive('reports.php', $current) ?>" title="Reports" href="<?= BASE_URL ?>/admin/reports.php">
    <i class="bi bi-bar-chart-line"></i><span class="bottom-nav-label">Reports</span>
  </a>
  
  <?php endif; ?>
  <?php if (userCan('audit.view')): ?>
  <a class="bottom-nav-link <?= navActive('audit-log.php', $current) ?>" title="Audit" href="<?= BASE_URL ?>/admin/audit-log.php">
    <i class="bi bi-clock-history"></i><span class="bottom-nav-label">Audit</span>
  </a>
  
  <?php endif; ?>
</nav>

