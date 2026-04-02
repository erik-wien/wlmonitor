<!DOCTYPE html>
<html lang="de">
<head>
	<title>Wiener Linien Abfahrtsmonitor</title>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta http-equiv="X-UA-Compatible" content="ie=edge">

	<meta name="application-name" content="WL Monitor">
	<meta name="mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-title" content="WL Monitor">
	<meta name="msapplication-TileColor" content="#000000">
	<meta name="theme-color" content="#000000">
	<meta name="apple-mobile-web-app-status-bar-style" content="#000000">
	<meta name="msapplication-config" content="img/browserconfig.xml?v=191025092929">
	<link rel="apple-touch-icon" sizes="57x57" href="img/apple-touch-icon-57x57.png?v=191025092929">
	<link rel="apple-touch-icon" sizes="60x60" href="img/apple-touch-icon-60x60.png?v=191025092929">
	<link rel="apple-touch-icon" sizes="72x72" href="img/apple-touch-icon-72x72.png?v=191025092929">
	<link rel="apple-touch-icon" sizes="76x76" href="img/apple-touch-icon-76x76.png?v=191025092929">
	<link rel="apple-touch-icon" sizes="114x114" href="img/apple-touch-icon-114x114.png?v=191025092929">
	<link rel="apple-touch-icon" sizes="120x120" href="img/apple-touch-icon-120x120.png?v=191025092929">
	<link rel="apple-touch-icon" sizes="144x144" href="img/apple-touch-icon-144x144.png?v=191025092929">
	<link rel="apple-touch-icon" sizes="152x152" href="img/apple-touch-icon-152x152.png?v=191025092929">
	<link rel="apple-touch-icon" sizes="180x180" href="img/apple-touch-icon-180x180.png?v=191025092929">
	<link rel="icon" type="image/png" href="img/android-chrome-36x36.png?v=191025092929" sizes="36x36">
	<link rel="icon" type="image/png" href="img/android-chrome-48x48.png?v=191025092929" sizes="48x48">
	<link rel="icon" type="image/png" href="img/android-chrome-72x72.png?v=191025092929" sizes="72x72">
	<link rel="icon" type="image/png" href="img/android-chrome-96x96.png?v=191025092929" sizes="96x96">
	<link rel="icon" type="image/png" href="img/android-chrome-144x144.png?v=191025092929" sizes="144x144">
	<link rel="icon" type="image/png" href="img/android-chrome-192x192.png?v=191025092929" sizes="192x192">
	<link rel="icon" type="image/png" href="img/favicon-16x16.png?v=191025092929" sizes="16x16">
	<link rel="icon" type="image/png" href="img/favicon-32x32.png?v=191025092929" sizes="32x32">
	<link rel="icon" type="image/png" href="img/favicon-96x96.png?v=191025092929" sizes="96x96">
	<link rel="shortcut icon" type="image/x-icon" href="img/favicon.ico?v=191025092929">
	<meta name="msapplication-TileImage" content="mstile-150x150.png?v=191025092929">
	<meta name="msapplication-square70x70logo" content="img/mstile-70x70.png?v=191025092929">
	<meta name="msapplication-square150x150logo" content="img/mstile-150x150.png?v=191025092929">
	<meta name="msapplication-wide310x150logo" content="img/mstile-310x150.png?v=191025092929">
	<meta name="msapplication-square310x310logo" content="img/mstile-310x310.png?v=191025092929">
	<link href="img/apple-touch-startup-image-320x460.png?v=191025092929" media="(device-width: 320px) and (device-height: 480px) and (-webkit-device-pixel-ratio: 1)" rel="apple-touch-startup-image">
	<link href="img/apple-touch-startup-image-640x920.png?v=191025092929" media="(device-width: 320px) and (device-height: 480px) and (-webkit-device-pixel-ratio: 2)" rel="apple-touch-startup-image">
	<link href="img/apple-touch-startup-image-640x1096.png?v=191025092929" media="(device-width: 320px) and (device-height: 568px) and (-webkit-device-pixel-ratio: 2)" rel="apple-touch-startup-image">
	<link href="img/apple-touch-startup-image-748x1024.png?v=191025092929" media="(device-width: 768px) and (device-height: 1024px) and (-webkit-device-pixel-ratio: 1) and (orientation: landscape)" rel="apple-touch-startup-image">
	<link href="img/apple-touch-startup-image-750x1024.png?v=191025092929" media="" rel="apple-touch-startup-image">
	<link href="img/apple-touch-startup-image-750x1294.png?v=191025092929" media="(device-width: 375px) and (device-height: 667px) and (-webkit-device-pixel-ratio: 2)" rel="apple-touch-startup-image">
	<link href="img/apple-touch-startup-image-768x1004.png?v=191025092929" media="(device-width: 768px) and (device-height: 1024px) and (-webkit-device-pixel-ratio: 1) and (orientation: portrait)" rel="apple-touch-startup-image">
	<link href="img/apple-touch-startup-image-1182x2208.png?v=191025092929" media="(device-width: 414px) and (device-height: 736px) and (-webkit-device-pixel-ratio: 3) and (orientation: landscape)" rel="apple-touch-startup-image">
	<link href="img/apple-touch-startup-image-1242x2148.png?v=191025092929" media="(device-width: 414px) and (device-height: 736px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)" rel="apple-touch-startup-image">
	<link href="img/apple-touch-startup-image-1496x2048.png?v=191025092929" media="(device-width: 768px) and (device-height: 1024px) and (-webkit-device-pixel-ratio: 2) and (orientation: landscape)" rel="apple-touch-startup-image">
	<link href="img/apple-touch-startup-image-1536x2008.png?v=191025092929" media="(device-width: 768px) and (device-height: 1024px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)" rel="apple-touch-startup-image">
	<link rel="manifest" href="img/manifest.json?v=191025092929" />

	<!-- Font Awesome -->
	<link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.7.0/css/all.css" integrity="sha384-lZN37f5QGtY3VHgisS14W3ExzMWZxybE1SJSEsQp9S+oqd12jhcu+A56Ebc1zFSJ" crossorigin="anonymous">
	<!--script src="https://kit.fontawesome.com/175f395c88.js" crossorigin="anonymous"></script-->

	<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto|Roboto+Mono|Share+Tech+Mono">

	<!-- Default CSS -->
	<link rel="stylesheet" id="bootstrapCss" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">

	<link rel="stylesheet" href="js/xpull/xpull.css">
	<link rel="stylesheet" href="style/wl-monitor.css">

</head>


<body style="padding: 70px 0px; margin: 0px;">
<div class="fixed-top w-100 p-0 container">
<nav class="navbar navbar-expand-sm bg-light py-2 border border-dark border-left-0 border-right-0 border-top-0">
	<!-- Logo -->
	<a class="navbar-brand" href="#" onclick="location.reload(true);">
		<img src="img/icons/train-front-m-bunt.svg" alt="Logo" style="width:30px;">
	</a>
	<ul class="navbar-nav d-flex align-items-center justify-content-between">
		<li class="nav-item d-flex">
			<form id="searchForm">
				<div class="input-group ml-1">
					<input id="stationFilter" class="form-control border border-dark text-dark flex-grow-1" type="text" placeholder="A-Z suchen" data-toggle="dropdown" autocomplete="off" style="height: 2.5rem;">
					<input id="s" name="s" class="form-control border border-dark text-dark flex-grow-1 d-none" type="text" placeholder="Namen suchen" style="height: 2.5rem;">
					<div class="input-group-append" id="stationSortorder" data-toggle="buttons">
						<div class="btn-group btn-group-toggle">
							<label class="btn btn-light px-0 rounded-0 active" for="stationSortAlpha" style="width: 50px !important;">
								<input type="radio" name="stationSortAlpha" id="stationSortAlpha" value="1" checked class="d-none"> <img src="img/icons/arrow-down-a-z-m-grey.svg">
							</label>
							<label class="btn btn-light px-0" for="stationSortDist" style="width: 50px !important;">
								<input type="radio" name="stationSortDist" id="stationSortDist" value="0"> <img src="img/icons/navigation-m-grey.svg">
							</label>
							<label class="btn btn-light px-0" for="stationSortSearch" style="width: 50px !important;">
								<input type="radio" name="stationSortSearch" id="stationSortSearch" value="0"> <img src="img/icons/scan-search-m-grey.svg">
							</label>
						</div>
					</div>
					<div class="dropdown-menu bg-light ml-auto">
						<ul id="stationList" class="list-group bg-light"></ul>
										</div>
									</div>
								</form>
		</li>
		
		<!-- Login Menu -->
		<li class="nav-item dropdown">
			<button class="btn" data-toggle="dropdown" id="currentUser">
				<img src="<?= $avatarDir ?>" id="menuUserImg" class="rounded-circle" alt="Profil Bild<?= $username ?>" />
			</button>

			<div class="dropdown-menu dropdown-menu-sm-right bg-light" style="order: 999;">
			
			<!-- Menu Items for loggedin users -->
<?php if ($loggedIn): ?>
				<div class="dropdown-header text-light">Sie sind angemeldet als <?= $username ?></div>
					
				<button class="dropdown-item mt-2 btn btn-dark" data-toggle="modal" data-target="#modalUser">
					<span class="fas fa-user-edit"></span>Benutzerkonto
				</button>
					
				<a class="dropdown-item btn btn-danker" href="logout.php">
					<span class="fas fa-user-alt-slash"></span>logout
				</a>
								
<!-- Menu Items for loggedout users -->
<?php else: ?>
				<div class="dropdown-header text-light">Melden Sie sich an:</div>
				
				<button class="dropdown-item btn btn-primary mt-2 " data-toggle="modal" data-target="#modalUser">
					<span class="fas fa-user"></span> Login</button>
<? endif; ?>
						
						<div class="divider pt-3"></div>
						
						<div class="dropdown-headertext-light ">Thema</div>
	
						<form class="form-horizontal">
							<div class="dropdown-item">
								<label class="checkbox text-dark"><input type="radio" name="themePreference" id="themelight" value="light"> hell</label>
							</div>
							<div class="dropdown-item">
								<label class="checkbox text-dark"><input type="radio" name="themePreference" id="themedark" value="dark"> dunkel</label>
							</div>
							<div class="dropdown-item">
								<label class="checkbox text-dark"><input type="radio" name="themePreference" id="themeauto" value="auto"> automatisch</label>
							</div>
						</form>
		
					</div>
	
				</li>	<!-- /#navbar-login -->
			</ul>
			
		</nav>
	</div>
