<?php
require_once(__DIR__ . '/../include/initialize.php');
require_once(__DIR__ . '/../inc/auth.php');

auth_logout($con);
addAlert('notice', 'Sie wurden abgemeldet.');
header('Location: index.php'); exit;
