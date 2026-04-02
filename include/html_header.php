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

  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.7.0/css/all.css"
        integrity="sha384-lZN37f5QGtY3VHgisS14W3ExzMWZxybE1SJSEsQp9S+oqd12jhcu+A56Ebc1zFSJ"
        crossorigin="anonymous">

  <!-- Google Fonts -->
  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto|Roboto+Mono|Share+Tech+Mono">

  <!-- Bootstrap 5 -->
  <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous">

  <!-- App styles -->
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="style/wl-monitor.css">
</head>
<body>
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
