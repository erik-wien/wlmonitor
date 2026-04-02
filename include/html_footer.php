
	<!--
	Navigation
	___________________________________________________
	-->
	<div class="container fixed-bottom w-100 bg-light border border-dark border-left-0 border-right-0 border-bottom-0">
		
		<nav class="navbar p-0">

			<!-- Navbar -->
			<ul id="userMenu" class="navbar-nav d-flex flex-row w-100 p-0 my-0 align-items-center justify-content-between" >
				<li class="nav-item">
					<button class="btn nav-link" onclick="toggleFullScreen();"><span class="fas fa-expand-arrows-alt"></span> Fullscreen</button>
				</li>

				<li class="nav-item">
					<button role='button' class='btn' animation="true" data-toggle='popover' data-html="true" data-placement="top"
						data-content="
							<a href='#' class='dropdown-item' data-toggle='modal' data-target='#modalHelp' id='navHelp'>
								Funktionen</a>

							<a href='#' class='dropdown-item' data-toggle='modal' data-target='#modalDocu' id='navDocu'>
								Dokumentation</a>
						">
						<i class='far fa-question-circle'></i> Hilfe
					</button>
				</li>

				<li class="nav-item">
					<button class="btn" animation="true" data-toggle="popover" data-html="true" data-placement="top"
						data-content="
							<a type='button' class='btn dropdown-item' href='https://about.me/erik.accart-huemer' target='_blank' id='navAbout'>
								About </a>

							<a type='button' class='btn dropdown-item' href='&#109;&#97;&#105;&#108;&#x74;&#111;&#x3a;&#x69;&#x6e;&#102;&#x6f;&#x40;&#x32;&#x6d;&#101;&#46;&#111;&#114;&#103;' id='navMail'>
								Mail</a>
						">
						<i class='far fa-envelope'></i> <div class='d-none d-sm-inline-block'>Kontakt</div>
					</button>

				</li>

			</ul>
		</nav>
	</div>

