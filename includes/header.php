<?php
/**
 * Top navigation bar.
 * Uses the logged-in user from the session when available.
 */
$headerUser = current_user();
$userName   = $headerUser ? $headerUser['first_name'] . ' ' . $headerUser['last_name'] : 'Guest';
$userRole   = $headerUser
    ? implode(' · ', array_filter([role_label($headerUser['role']), $headerUser['field_engineer_id'], $headerUser['location']]))
    : '';
?>
  <!-- ======= Header ======= -->
  <header id="header" class="header fixed-top d-flex align-items-center">

    <div class="d-flex align-items-center justify-content-between">
      <a href="index.php" class="logo d-flex align-items-center">
        <img src="mistery.jpg" alt="">
        <span class="d-none d-lg-block"><?= e(APP_NAME) ?></span>
      </a>
      <i class="bi bi-list toggle-sidebar-btn"></i>
    </div><!-- End Logo -->

    <div class="search-bar">
      <form class="search-form d-flex align-items-center" method="GET" action="#">
        <input type="text" name="query" placeholder="Search" title="Enter search keyword">
        <button type="submit" title="Search"><i class="bi bi-search"></i></button>
      </form>
    </div><!-- End Search Bar -->

    <nav class="header-nav ms-auto">
      <ul class="d-flex align-items-center">

        <li class="nav-item d-block d-lg-none">
          <a class="nav-link nav-icon search-bar-toggle" href="#">
            <i class="bi bi-search"></i>
          </a>
        </li><!-- End Search Icon -->

        <li class="nav-item dropdown">

<?php
    $notifUnread = $headerUser ? notifications_unread_count((int) $headerUser['user_id']) : 0;
    $notifItems  = $headerUser ? notifications_latest((int) $headerUser['user_id'], 5) : [];
?>
          <a class="nav-link nav-icon" href="#" data-bs-toggle="dropdown" aria-label="Notifications">
            <i class="bi bi-bell"></i>
            <span class="badge bg-danger badge-number<?= $notifUnread ? '' : ' d-none' ?>" id="notifCount"><?= $notifUnread ?></span>
          </a><!-- End Notification Icon -->

          <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow notifications" id="notifList">
            <?= notifications_dropdown_html($notifUnread, $notifItems) ?>
          </ul><!-- End Notification Dropdown Items -->

<?php if ($headerUser): ?>
          <script>
            // Refresh the bell every 30 seconds so new registrations show up without reloading.
            (function () {
              var count = document.getElementById("notifCount");
              var list = document.getElementById("notifList");
              function refresh() {
                if (list.classList.contains("show")) { return; }   // don't redraw while it's open
                fetch("notifications_api.php", { headers: { "Accept": "application/json" } })
                  .then(function (res) { return res.ok ? res.json() : null; })
                  .then(function (data) {
                    if (!data) { return; }
                    count.textContent = data.unread;
                    count.classList.toggle("d-none", data.unread === 0);
                    list.innerHTML = data.html;
                  })
                  .catch(function () {});
              }
              setInterval(refresh, 30000);
            })();
          </script>
<?php endif; ?>

        </li><!-- End Notification Nav -->

        <li class="nav-item dropdown pe-3">

          <a class="nav-link nav-profile d-flex align-items-center pe-0" href="#" data-bs-toggle="dropdown">
            <img src="<?= e(profile_picture_url($headerUser)) ?>" alt="Profile" class="rounded-circle" style="width: 36px; height: 36px; object-fit: cover">
            <span class="d-none d-md-block dropdown-toggle ps-2"><?= e($userName) ?></span>
          </a><!-- End Profile Image Icon -->

          <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow profile">
            <li class="dropdown-header">
              <h6><?= e($userName) ?></h6>
              <span><?= e($userRole) ?></span>
            </li>
            <li>
              <hr class="dropdown-divider">
            </li>

            <li>
              <a class="dropdown-item d-flex align-items-center" href="#" data-bs-toggle="modal" data-bs-target="#profileModal">
                <i class="bi bi-person-gear"></i>
                <span>Edit Profile</span>
              </a>
            </li>
            <li>
              <hr class="dropdown-divider">
            </li>

            <li>
              <a class="dropdown-item d-flex align-items-center" href="logout.php">
                <i class="bi bi-box-arrow-right"></i>
                <span>Sign Out</span>
              </a>
            </li>

          </ul><!-- End Profile Dropdown Items -->
        </li><!-- End Profile Nav -->

      </ul>
    </nav><!-- End Icons Navigation -->

  </header><!-- End Header -->
<?php if ($headerUser) { require __DIR__ . '/profile_modal.php'; } ?>
