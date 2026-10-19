<?php
/**
 * Sidebar navigation.
 * Edit $menu to add/remove links. Set $activePage on each page to the
 * matching 'key' so the link is highlighted (and its group opened).
 * Add 'roles' => [...] to show an item (or heading) only to those roles,
 * and 'badge' => number to show a red count next to the label.
 */
$sidebarUser = current_user();
$pendingCount = is_admin($sidebarUser) ? pending_registration_count() : 0;

// Admin badge on Route Fares: engineer fares waiting for approval + open "wrong fare" reports.
$transpoCount = 0;
if (is_admin($sidebarUser)) {
    require_once __DIR__ . '/transpo.php';
    $transpoCount = array_sum(transpo_pending_counts());
}

$menu = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'bi-grid', 'href' => 'index.php'],

    ['key' => 'time-clock',   'label' => 'Time Clock', 'icon' => 'bi-clock',   'href' => 'time_clock.php',   'roles' => ['field_engineer', 'admin']],
    ['key' => 'time-records', 'label' => 'My Records', 'icon' => 'bi-table',   'href' => 'time_records.php', 'roles' => ['field_engineer', 'admin']],
    ['key' => 'das',          'label' => 'My DAS',     'icon' => 'bi-journal-text', 'href' => 'das.php', 'roles' => ['field_engineer', 'admin']],

    ['heading' => 'Administration', 'roles' => ['admin']],

    ['key' => 'registrations',      'label' => 'Registrations',      'icon' => 'bi-person-check',   'href' => 'registrations.php',      'roles' => ['admin'], 'badge' => $pendingCount],
    ['key' => 'registration',       'label' => 'Register User',      'icon' => 'bi-person-plus',    'href' => 'registration.php',       'roles' => ['admin']],
    ['key' => 'locations',          'label' => 'Locations',          'icon' => 'bi-geo-alt',        'href' => 'locations.php',          'roles' => ['admin']],
    ['key' => 'engineer-schedules', 'label' => 'User Schedules',     'icon' => 'bi-calendar-week', 'href' => 'engineer_schedules.php', 'roles' => ['admin']],
    ['key' => 'time-settings',      'label' => 'Time Settings',      'icon' => 'bi-sliders',        'href' => 'time_settings.php',      'roles' => ['admin']],
    ['key' => 'email-recipients',   'label' => 'Email Recipients',   'icon' => 'bi-envelope-at',    'href' => 'email_recipients.php',   'roles' => ['admin']],
    ['key' => 'email-templates',    'label' => 'Email Templates',    'icon' => 'bi-card-text',      'href' => 'email_templates.php',    'roles' => ['admin']],

    ['heading' => 'Transportation'],

    ['key' => 'transpo-filing',    'label' => 'Transpo Filing',    'icon' => 'bi-bus-front',        'href' => 'transpo_filing.php',    'roles' => ['field_engineer', 'admin']],
    ['key' => 'route-fares',       'label' => 'Route Fares',       'icon' => 'bi-signpost-split',   'href' => 'route_fares.php',       'roles' => ['field_engineer', 'admin'], 'badge' => $transpoCount],
    ['key' => 'transpo-locations', 'label' => 'Transpo Locations', 'icon' => 'bi-pin-map',          'href' => 'transpo_locations.php', 'roles' => ['admin']],
    ['key' => 'transpo-reports',   'label' => 'Transpo Reports',   'icon' => 'bi-receipt',          'href' => 'transpo_reports.php',   'roles' => ['admin']],
    ['key' => 'transpo-import',    'label' => 'Transpo Import',    'icon' => 'bi-file-earmark-arrow-up', 'href' => 'transpo_import.php', 'roles' => ['admin']],

    ['heading' => 'Terminals'],

    ['key' => 'terminals', 'label' => 'Terminals', 'icon' => 'bi-credit-card-2-front', 'href' => 'terminals.php'],
    ['key' => 'terminal-labels', 'label' => 'Terminal Labels', 'icon' => 'bi-tags', 'href' => 'terminal_labels.php', 'roles' => ['admin']],

    ['heading' => 'Serial Inventory', 'roles' => ['field_engineer', 'admin']],

    ['key' => 'serial-scan',    'label' => 'Scan',    'icon' => 'bi-upc-scan', 'href' => 'serial_scan.php',    'roles' => ['field_engineer', 'admin']],
    ['key' => 'serial-records', 'label' => 'Records', 'icon' => 'bi-list-ol',  'href' => 'serial_records.php', 'roles' => ['field_engineer', 'admin']],

    ['heading' => 'Email Generator', 'roles' => ['field_engineer', 'admin']],

    ['key' => 'service-request', 'label' => 'Service Request', 'icon' => 'bi-envelope-paper', 'href' => 'service_request.php', 'roles' => ['field_engineer', 'admin']],
    ['key' => 'atw-request',     'label' => 'ATW Request',     'icon' => 'bi-envelope-plus',  'href' => 'atw_request.php',     'roles' => ['field_engineer', 'admin']],
    ['key' => 'my-requests',     'label' => is_admin($sidebarUser) ? 'Email Requests' : 'My Requests', 'icon' => 'bi-envelope-check', 'href' => 'my_requests.php', 'roles' => ['field_engineer', 'admin']],

    ['heading' => 'Account'],

    ['key' => 'logout', 'label' => 'Sign Out', 'icon' => 'bi-box-arrow-right', 'href' => 'logout.php'],

    // Example of a collapsible group:
    // ['key' => 'tables', 'label' => 'Tables', 'icon' => 'bi-layout-text-window-reverse', 'children' => [
    //     ['key' => 'tables-general', 'label' => 'General Tables', 'href' => 'tables-general.php'],
    //     ['key' => 'tables-data',    'label' => 'Data Tables',    'href' => 'tables-data.php'],
    // ]],
];

$activePage = $activePage ?? '';

$sidebarRole = $sidebarUser['role'] ?? null;
$menu = array_filter($menu, fn($item) => !isset($item['roles']) || in_array($sidebarRole, $item['roles'], true));
?>
  <!-- ======= Sidebar ======= -->
  <aside id="sidebar" class="sidebar">

    <ul class="sidebar-nav" id="sidebar-nav">
<?php foreach ($menu as $item): ?>
<?php if (isset($item['heading'])): ?>

      <li class="nav-heading"><?= e($item['heading']) ?></li>
<?php elseif (!empty($item['children'])):
        $groupId  = $item['key'] . '-nav';
        $isOpen   = in_array($activePage, array_column($item['children'], 'key'), true);
?>

      <li class="nav-item">
        <a class="nav-link <?= $isOpen ? '' : 'collapsed' ?>" data-bs-target="#<?= e($groupId) ?>" data-bs-toggle="collapse" href="#">
          <i class="bi <?= e($item['icon']) ?>"></i><span><?= e($item['label']) ?></span><i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="<?= e($groupId) ?>" class="nav-content collapse <?= $isOpen ? 'show' : '' ?>" data-bs-parent="#sidebar-nav">
<?php foreach ($item['children'] as $child): ?>
          <li>
            <a href="<?= e($child['href']) ?>" class="<?= $activePage === $child['key'] ? 'active' : '' ?>">
              <i class="bi bi-circle"></i><span><?= e($child['label']) ?></span>
            </a>
          </li>
<?php endforeach; ?>
        </ul>
      </li><!-- End <?= e($item['label']) ?> Nav -->
<?php else: ?>

      <li class="nav-item">
        <a class="nav-link <?= nav_class($item['key']) ?>" href="<?= e($item['href']) ?>">
          <i class="bi <?= e($item['icon']) ?>"></i>
          <span><?= e($item['label']) ?></span>
<?php if (!empty($item['badge'])): ?>
          <span class="badge bg-danger rounded-pill ms-auto"><?= (int) $item['badge'] ?></span>
<?php endif; ?>
        </a>
      </li><!-- End <?= e($item['label']) ?> Nav -->
<?php endif; ?>
<?php endforeach; ?>

    </ul>

  </aside><!-- End Sidebar -->
  <script>
    // Keep the sidebar's scroll position when opening another page, so it doesn't jump back to the top.
    (function () {
      var sidebar = document.getElementById("sidebar");
      var saved = null;
      try { saved = sessionStorage.getItem("sidebarScroll"); } catch (e) {}
      if (saved !== null) {
        sidebar.scrollTop = parseInt(saved, 10) || 0;
      }
      // First visit (or the position was lost): make sure the current page's link is visible.
      var active = sidebar.querySelector(".nav-link:not(.collapsed), .nav-content a.active");
      if (active) {
        var box = sidebar.getBoundingClientRect(), link = active.getBoundingClientRect();
        if (link.top < box.top || link.bottom > box.bottom) {
          sidebar.scrollTop += link.top - box.top - box.height / 2 + link.height / 2;
        }
      }
      function remember() {
        try { sessionStorage.setItem("sidebarScroll", String(sidebar.scrollTop)); } catch (e) {}
      }
      sidebar.addEventListener("click", remember);
      window.addEventListener("pagehide", remember);
    })();
  </script>
