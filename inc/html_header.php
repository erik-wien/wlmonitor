<!DOCTYPE html>
<html lang="de">
<head>
  <title>Wiener Linien Abfahrtsmonitor</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="ie=edge">
  <meta name="application-name" content="WL Monitor">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="WL Monitor">
  <meta name="theme-color" content="#000000">
  <link rel="shortcut icon" type="image/x-icon" href="img/favicon.ico">
  <link rel="apple-touch-icon" sizes="180x180" href="img/apple-touch-icon-180x180.png">
  <link rel="manifest" href="img/manifest.json">
  <link rel="stylesheet" href="css/shared/theme.css">
  <link rel="stylesheet" href="css/shared/reset.css">
  <link rel="stylesheet" href="css/shared/layout.css">
  <link rel="stylesheet" href="css/shared/components.css">
  <link rel="stylesheet" href="css/app/wl-monitor.css">
</head>
<body>
<?php
// Inline SVG sprite (one request, cached by browser)
$_spritePath = __DIR__ . '/../web/css/icons.svg';
if (file_exists($_spritePath)) { readfile($_spritePath); }
unset($_spritePath);
?>
