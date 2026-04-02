
	<!--
	Alerts
    ___________________________________________________
	 -->
	<div class="container">
		<div class="row" id="alerts"></div>
	</div>

	<!--
	Body
    ___________________________________________________
     -->
	<div id="content" class="container">
		<div class="xpull">
			<div class="xpull__start-msg">
				<p class="xpull__start-msg-text" style="color:#bbb;">Zum Aktualisieren nach unten ziehen &amp; los lassen. </p>
				<div class="xpull__arrow"></div>
			</div>
			<div class="xpull__spinner">
				<div class="xpull__spinner-circle"></div>
			</div>
		</div>
		
		<div class="row">

			<div class="col-md-6 p-1" id="monitor"><i class="fas fa-spinner fa-spin"></i></div>

			<div class="col-md-6 mt-md-4 p-3" id="buttons"><i class="fas fa-spinner fa-spin"></i></div>

			<?php
			if ( isset($_SESSION['loggedin']) ) {
				echo "<div id='SaveFavorites'>";
				// echo "<button id='btnSaveFavorites' class='btn btn-outline-success btn-xs float-right mr-3'>Reihenfolge speichern</button>";
				echo "</div>\n";
			}
			?>
		</div>

	</div>


	<!--
	Footer
	====================================================================== -->
	<div class="row text-muted">
		<div class="col-sm-12 col-md-5 ml-auto text-center small">
			Version 2.2 27.10.2023 &copy; 2023 by Erik R. Huemer 
		</div>

		<div class="col-sm-12 col-md-5 mr-auto text-center">
			<small>Datenquelle: Stadt Wien – data.wien.gv.at</small>
		</div>
	</div>



	<!-- Helper Elements
	=========================================================================	-->

	<!-- Go Back Top Button-->
	<button onclick="topFunction()" id="topBtn" title="Go to top" class="btn btn-danger"><span class="fas fa-arrow-alt-circle-up"></span></button>

