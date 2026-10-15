<?php
// AdoptAPet single-page application shell backed by the PHP API.
require_once dirname(__DIR__) . '/config/http-security.php';
require_once dirname(__DIR__) . '/config/security.php';
// The SPA contains private authentication and MFA routes behind hash URLs.
header('Cache-Control: private, no-store', true);
header('Pragma: no-cache', true);
header('Expires: 0', true);
$hcaptcha = hcaptchaConfiguration();
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>AdoptAPet — Web-Based Pet Adoption System</title>
  <link rel="preconnect" href="https://images.unsplash.com">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <link rel="stylesheet" href="assets/css/style.css?v=136">
  <link rel="stylesheet" href="assets/css/auth.css?v=7">
  <link rel="stylesheet" href="assets/css/security.css?v=2">
  <link rel="stylesheet" href="assets/css/account.css?v=6">
    <link rel="stylesheet" href="assets/css/admin-audit.css?v=6">
</head>
<body>
  <div id="app"></div>
  <div id="toast-region" aria-live="polite"></div>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script>window.ADOPTAPET_SECURITY = <?= json_encode([
      'hcaptchaEnabled' => $hcaptcha['enabled'] && $hcaptcha['configured'],
      'hcaptchaSiteKey' => $hcaptcha['enabled'] && $hcaptcha['configured'] ? $hcaptcha['site_key'] : '',
  ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
  <script src="assets/js/app.js?v=57"></script>
  <script src="assets/js/admin-mfa.js?v=4"></script>
  <script src="assets/js/security.js?v=6"></script>
  <script src="assets/js/admin-audit.js?v=5"></script>
  <script src="assets/js/dashboards.js?v=4"></script>
  <script src="assets/js/image-editor.js?v=8"></script>
  <script src="assets/js/pets.js?v=36"></script>
  <script src="assets/js/stray-reports.js?v=21"></script>
  <script src="assets/js/home.js?v=5"></script>
  <script src="assets/js/applications.js?v=17"></script>
  <script src="assets/js/profile.js?v=25"></script>
  <script src="assets/js/admin-users.js?v=13"></script>
  <script src="assets/js/analytics.js?v=3"></script>
  <script src="assets/js/donations.js?v=26"></script>
  <script src="assets/js/community.js?v=25"></script>
  <script src="assets/js/notifications.js?v=18"></script>
  <script src="assets/js/account.js?v=6"></script>
  <script src="assets/js/news.js?v=13"></script>
  <script src="assets/js/chat-tickets.js?v=15"></script>
  <?php if ($hcaptcha['enabled'] && $hcaptcha['configured']): ?>
  <script src="https://js.hcaptcha.com/1/api.js?onload=onHcaptchaReady&amp;render=explicit" async defer></script>
  <?php endif; ?>
</body>
</html>
