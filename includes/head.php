<?php
/**
 * Opens the document: <!DOCTYPE>, <head> and <body>.
 * Optional variables set by the page before including:
 *   $pageTitle  - text shown in the browser tab
 */
$pageTitle = $pageTitle ?? 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">

  <title><?= e($pageTitle) ?> - <?= e(APP_NAME) ?></title>
  <meta content="" name="description">
  <meta content="" name="keywords">

  <!-- Installable app (Add to Home Screen). manifest.json is served with no-cache, see .htaccess. -->
  <link rel="manifest" href="manifest.json">
  <meta name="theme-color" content="#012970">

  <!-- Favicons -->
  <link href="<?= asset('mistery.jpg') ?>" rel="icon">
  <link href="<?= asset('mistery.jpg') ?>" rel="apple-touch-icon">

  <!-- Google Fonts -->
  <link href="https://fonts.gstatic.com" rel="preconnect">
  <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i" rel="stylesheet">

  <!-- Vendor CSS Files -->
  <link href="<?= asset('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= asset('assets/vendor/bootstrap-icons/bootstrap-icons.css') ?>" rel="stylesheet">
  <link href="<?= asset('assets/vendor/boxicons/css/boxicons.min.css') ?>" rel="stylesheet">
  <link href="<?= asset('assets/vendor/quill/quill.snow.css') ?>" rel="stylesheet">
  <link href="<?= asset('assets/vendor/quill/quill.bubble.css') ?>" rel="stylesheet">
  <link href="<?= asset('assets/vendor/remixicon/remixicon.css') ?>" rel="stylesheet">
  <link href="<?= asset('assets/vendor/simple-datatables/style.css') ?>" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="<?= asset('assets/css/style.css') ?>" rel="stylesheet">
</head>

<body>
