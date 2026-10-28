<?php
/**
 * Tom Select + the shared transpo script, with its settings.
 * Include on transpo pages after includes/scripts.php has loaded Bootstrap.
 */
?>
  <link href="<?= asset('assets/vendor/tom-select/tom-select.bootstrap5.min.css') ?>" rel="stylesheet">
  <link href="<?= asset('assets/css/transpo.css') ?>" rel="stylesheet">
  <script src="<?= asset('assets/vendor/tom-select/tom-select.complete.min.js') ?>"></script>
  <script>
    window.TRANSPO = {
      api: "transpo_api.php",
      csrf: <?= json_encode(csrf_token()) ?>,
      types: <?= json_encode(TRANSPO_LOCATION_TYPES) ?>,
      modes: <?= json_encode((object) transpo_modes()) ?>
    };
  </script>
  <script src="<?= asset('assets/js/transpo.js') ?>"></script>
