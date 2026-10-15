<?php
/**
 * Tom Select + the shared transpo script, with its settings.
 * Include on transpo pages after includes/scripts.php has loaded Bootstrap.
 */
?>
  <link href="assets/vendor/tom-select/tom-select.bootstrap5.min.css" rel="stylesheet">
  <link href="assets/css/transpo.css" rel="stylesheet">
  <script src="assets/vendor/tom-select/tom-select.complete.min.js"></script>
  <script>
    window.TRANSPO = {
      api: "transpo_api.php",
      csrf: <?= json_encode(csrf_token()) ?>,
      types: <?= json_encode(TRANSPO_LOCATION_TYPES) ?>,
      modes: <?= json_encode(TRANSPO_MODES) ?>
    };
  </script>
  <script src="assets/js/transpo.js"></script>
