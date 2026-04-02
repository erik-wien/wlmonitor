<?php
/**

 * index.php
 *
 * Main document of WL Monitor
 *
 * index.php consist of the following Parts:
 *		- Navigation
 *		- Monitor
 *		- Favorites
 *		- Footer
 *
 * Additionally there two alerts:
 *		- User errors (below the Navigation)
 *		- A cookie hint-Box at the bottom of the Page
 *
 * Dependencies:
 *		- Bootstrap V.4.3.1
 *		- Fontawesome V.5.7.0
 *		- Google Fonts: Roboto, Roboto Mono, Share Tech Mono
 *		- jQuery V.3.3.1
 *		- Geodys V.2.0.0 curtesy of Chris Veness
 *		- Wiener Linien ogd_realtime monitor
 *			Datenquelle: Stadt Wien – data.wien.gv.at
 *		- wl.json / Wiener Lienien Generator by Patrick "hactar" Wolowicz
 *
 *
 * Sitting on the shoulders of Matthias Bendel who inspired me with his project WL-Monitor-Pi.
 *
 * PHP version 8.2
 *
 * LICENSE: This source file is subject to version 3.01 of the PHP license
 * that is available through the world-wide-web at the following URI:
 * http://www.php.net/license/3_01.txt.  If you did not receive a copy of
 * the PHP License and are unable to obtain it through the web, please
 * send a note to license@php.net so we can mail you a copy immediately.
 *
 * @category   geo-information
 * @package	wl-monitor
 * @author	 Erik R. Huemer <erik.huemer@jardyx.com>
 * @copyright  2019 Erik R. Huemer
 * @license	http://www.php.net/license/3_01.txt  PHP License 3.01
 * @version	SVN: $Id$
 * @link	   https://www.jardyx.com/wl-monitor/download/wl-monitor.zip
 * @see		https://www.jardyx.com/wl-monitor/
 * @since	  File available since Release 1.2.0
 * @deprecated not depreciated
 */

	require_once(__DIR__ . '/../include/initialize.php');
	header('Content-Type: text/html; charset=utf-8');
    include_once(__DIR__ . '/../include/html_header.php');
    include_once(__DIR__ . '/../include/html_footer.php');
    include_once(__DIR__ . '/../include/html_body.php');


?>


	<!--
	Modal Boxes
	Documentation Box
	======================================================================
	-->
	<div id="modalDocu" class="modal fade" role="dialog">
		<div class="modal-dialog modal-lg">

			<!-- Modal content-->
			<div class="modal-content">

				<div class="modal-header">
					<button type="button" class="close" data-dismiss="modal">&times;</button>
				</div>

				<div class="modal-body" id="docuContent"></div>

				<div class="modal-footer">
					<button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
				</div>
			</div>
		</div>
	</div>


	<!--
	Help Box
	======================================================================
	-->
	<div id="modalHelp" class="modal fade" role="dialog">
		<div class="modal-dialog modal-lg">

			<!-- Modal content-->
			<div class="modal-content">

				<div class="modal-header">

					<h5>Hilfe</h5>

					<form class="form-inline ml-5 shadow" data-toggle="dropdown">
						<div class="input-group input-group-md">
							<div class="input-group-prepend">
								<span class="input-group-text fas fa-search pt-2"></span>
							</div>
							<input id="helpFilter" class="form-control mr-sm-2 bg-dark text-light w-75" type="text" placeholder="Wobei benötigen Sie Hilfe?" autocomplete="on" style="border: 0px;"/>
						</div>
					</form>

					<button type="button" class="close" data-dismiss="modal">&times;</button>

				</div>

				<div id="helpContent" class="modal-body">
					<div id="helpListe"></div>

					<p>Ist eine Frage offen geblieben? Schicken Sie uns ein <a href="&#109;&#97;&#105;&#108;&#x74;&#111;&#x3a;&#x69;&#x6e;&#102;&#x6f;&#x40;&#x32;&#x6d;&#101;&#46;&#111;&#114;&#103;">Mail</a>!</p>
				</div>

				<div class="modal-footer">
					<button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
				</div>
			</div>
		</div>
	</div>

	<!-- Login / Register Forms
	======================================================================
	-->

	<!--
	Modal: Tabs
	___________________________________________________ -->

<div class="modal fade" id="modalUser" tabindex="-1" role="dialog" aria-labelledby="modalUser" aria-hidden="true">
	<div class="modal-dialog modal-lg cascading-modal" role="document">

		<!--Content-->
		<div class="modal-content">

			<!--Modal cascading tabs-->
			<div class="modal-c-tabs">

				<!-- Nav tabs -->
				<ul id="userModalMenu" class="nav nav-tabs md-tabs tabs-2 light-blue darken-3" role="tablist">

<?php if ($loggedIn): ?>
					<li class="nav-item"><a class="nav-link active" id="logintab1" data-toggle="tab" href="#panel1" role="tab"><i class="far fa-address-card mr-1"></i> Profil</a></li>
					<li class="nav-item"><a class="nav-link" 		id="logintab2" data-toggle="tab" href="#panel2" role="tab"><i class="fas fa-clock mr-1"></i> 		Log</a></li>
<?php else: ?> 

					<li class="nav-item"><a class="nav-link active" id="logintab7" data-toggle="tab" href="#panel7" role="tab"><i class="fas fa-user mr-1"></i> 		Anmelden</a></li>
					<li class="nav-item"><a class="nav-link" 		id="logintab8" data-toggle="tab" href="#panel8" role="tab"><i class="fas fa-user-plus mr-1"></i> 	Registrieren</a></li>
<?php endif ?>

				</ul>
			</div>



			<!-- Tab panels
			Quelle: https://mdbootstrap.com/docs/jquery/modals/forms/
			-->
			<div class="tab-content">

<?php if ($loggedIn): ?>

		<!--Panel 1 Profile
	___________________________________________________ -->
		<div class="tab-pane fade in show active" id="panel1" role="tabpanel" >

			<!--Body-->
			<div class="modal-body mb-1">
				<table class='table table-secondary mt-5'>
					<tr>
						<th>Name</th>
						<td><?=$_SESSION['username']?> </td>
					</tr>
					<tr>
						<th>E-Mail</th>
						<td><?=$_SESSION['email']?> </td>
					</tr>
					<tr>
						<th>Bild</th>
						<td><?=$_SESSION['img']?> </td>
					</tr>
					<tr>
						<th>letzte Anmeldung <button class="btn btn-sm btn-outline-dark border-0 p-0 m-0 ml-1"  data-toggle="tooltip" title="Zeitpunkt der letzten Anmeldung." data-placement="right">&#9432;</a></th>
						<td><?=$_SESSION['lastLogin']?></td>
					</tr>
					<tr>
						<th># Abfahrtszeiten <button class="btn btn-sm btn-outline-dark border-0 p-0 m-0 ml-1"  data-toggle="tooltip" title="Anzahl der Abfahrtszeiten, die gezeigt werden sollen." data-placement="right">&#9432;</a></th>
						<td><?=$_SESSION['departures'] ?> </td>
					</tr>
					<tr>
						<th>Kontotyp <button class="btn btn-sm btn-outline-dark border-0 p-0 m-0 ml-1"  title="Kontotyp/Rechte." data-toggle="tooltip" data-placement="right">&#9432;</a></th>
						<td><?=$_SESSION['rights'] ?> <?=(($_SESSION["disabled"] == 1) ? "gesperrt" : ""); ?></td>
					</tr>

					<tr>
						<th>Cookies
							<button id="profileCookies" class="btn btn-outline-dark btn-sm border-0 p-0 m-0 ml-1" data-toggle="tooltip" data-placement="right"
								title="Wenn Sie die Cookies löschen, werden Sie abgemeldet und zur Station Stephansplatz gebracht"
								data-content=""
							>&#9432;</button>

						</th>
						<td>
							<button class="btn btn-danger" onclick = 'removeCookies();location.reload();'>Cookies löschen</button>

						</td>
					</tr>
				</table>
			</div>


			<!--Footer-->
			<div class="modal-footer">
				<div class="options text-center text-md-right mt-1">
					<p><a data-toggle="tab" href="#panel6" role="tab"     class="btn btn—primary">Profil ändern</a></p>
					<p><a data-toggle="tab" href="#panel9" role="tab"     class="btn btn—primary">Kennwort ändern</a></p>
					<p><a href="#" onclick='$("#logintab9").tab("show");' class="btn btn—primary">Kennwort vergessen?</a></p><br />

					<p><a href="logout.php" class="btn btn—outline-danger font-weight-bold text-body">Abmelden</a></p>
				</div>

				<button type="button" class="btn btn-outline-primary waves-effect ml-auto" data-dismiss="modal">Schliessen</button>
			</div>
		</div>
		<!--/.Panel 1->




		<!--Panel 2:  Log
	___________________________________________________ -->
		<div class="tab-pane fade in show" id="panel2" role="tabpanel" >
			<!--Body-->
			<div class="modal-body mb-1">

				<nav aria-label="LogPage navigation">
					<ul id="logPagination" class="pagination">
					</ul>
				</nav>

				<select name="limit-records" id="limit-records" onchange="loadLog()">
					<optgroup label="Einträge pro Seite">
						<option value="10" >10</option>
						<option value="20">20</option>
						<option value="50">50</option>
						<option value="100">100</option>
						<option value="500">500</option>
						<option value="1000">1000</option>
						<option value="5000">5000</option>
					</optgroup>
				</select><br /><br />

				<table id='log' class='table table-sm table-striped'>
					<thead class="thead-light">
						<tr> <th>Log Time</th> <th>Context</th> <th>Activity</th> <th>Origin</th> <th>IP-Adress</th></tr>
					</thead>
					<tbody id="logTable">

					</tbody>
				</table>

			</div>


			<!--Footer-->
			<div class="modal-footer">
				<button type="button" class="btn btn-outline-primary waves-effect ml-auto" data-dismiss="modal">Schliessen</button>
			</div>
		</div>
		<!--/.Panel 2->




		<!--Panel 6 Edit Userprofile
	___________________________________________________ -->
		<div class="tab-pane fade" id="panel6" role="tabpanel">

			<div class="alert alert-danger invisible" id="editUserprofileError"></div>

				<!--Body-->
				<form action="editUserprofile.php" id="editUserprofile" method="post">

				<div class="modal-body">
					<div class="input-group has-warning md-form form-sm mb-5 mt-5 has-error has-success">
						<span class="input-group-prepend p-2 bg-dark text-light" style="width: 6rem;">Name</span>
						<input type="text" id="username" name="username" class="form-control border-0 validate" placeholder="Ihr Benutzername" value="<?=$_SESSION['username'] ?>">
					</div>
					
					<div class="input-group md-form form-sm mb-5 has-warning has-error has-success">
						<span class="input-group-prepend p-2 bg-dark text-light" style="width: 6rem;">E-Mail</span>
						<input type="text" id="email" name="email" class="form-control border-0 validate" placeholder="Ihre Mailadresse" autocomplete="off"  value="<?=$_SESSION['email'] ?>" >
					</div>
					
					<div class="input-group md-form form-sm mb-5 has-warning has-error has-success">
						<span class="input-group-prepend p-2 bg-dark text-light" style="width: 6rem;">Abfahrten</span>
						<input type="text" id="departures" name="departures" class="form-control border-0 validate" placeholder="Anzahl der Abfahrtszeiten" autocomplete="off"  value="<?=$_SESSION['departures'] ?>">
					</div>
					
					<?php if ($_SESSION['rights'] ="Admin"): ?>
					<div class="input-group md-form form-sm mb-5 has-warning has-error has-success">
						<span class="input-group-prepend p-2 bg-dark text-light" style="width: 6rem;">Debug Modus?</span>
						<input type="checkbox" id="debug" name="debug" class="form-control form-check-input border-0" value=1 <? $_SESSION['debug'] ? "checked" : "" ?> >
					</div>
					
					<?php else: ?>
						<input type="hidden" id="debug" name="debug" value=0>
						
					<?php endif; ?>
					
					<div class="text-center form-sm mt-2">
						<button class="btn btn-primary"><i class="fas fa-save ml-1"></i> Speichern <i class="fas fa-sign-in ml-1"></i></button>
					</div>
				</div>
				
				
				</form>

				<!--Footer-->
				<div class="modal-footer">
					<div class="options text-center text-md-right mt-1">
						<p><a data-toggle="tab" href="#panel9" role="tab">Kennwort ändern</a></p>
					</div>

					<button type="button" class="btn btn-outline-primary waves-effect ml-auto" data-dismiss="modal">Schliessen</button>
				</div>
		</div>
		<!--/.Panel 6-->




		<!--Panel 9 Change password
	___________________________________________________ -->
		<div class="tab-pane fade" id="panel9" role="tabpanel">

			<div class="alert alert-danger invisible" id="changePasswordError"></div>

				<!--Body-->
				<form action="changePassword.php" id="changePassword" method="post">

				<div class="modal-body">
					<div class="input-group has-warning md-form form-sm mb-5 mt-5 has-error has-success">
						<span class="input-group-prepend p-2 bg-dark text-light"  style="min-width: 2.25rem;"><i class="fas fa-lock-open prefix"></i></span>
						<input type="password" id="oldPassword" name="oldPassword" class="form-control border-0 validate" placeholder="Derzeitiges Kennwort" autocomplete="email">
						<div class="input-group-append p-2 bg-secondary" style="min-width: 2.25rem;">
							<span class="fas fa-check text-success form-control-feedback invisible"></span>
							<span class="fa fa-warning text-warning form-control-feedback invisible"></span>
							<span class="fa fa-remove text-danger form-control-feedback invisible"></span>
						</div>
					</div>

					<div class="input-group md-form form-sm mb-5 has-warning has-error has-success">
						<span class="input-group-prepend p-2 bg-dark text-light" style="min-width: 2.25rem;"><i class="fas fa-lock prefix"></i></span>
						<input type="password" id="newPassword1" name=" newPassword1"  class="form-control border-0 validate" placeholder="Neues Kennwort" autocomplete="off" >
						<div class="input-group-append p-2 bg-secondary" style="min-width: 2.25rem;">
							<span class="fas fa-check text-success form-control-feedback invisible"></span>
							<span class="fa fa-warning text-warning form-control-feedback invisible"></span>
							<span class="fa fa-remove text-danger form-control-feedback invisible"></span>
						</div>
					</div>


					<div class="input-group md-form form-sm mb-5 has-warning has-error has-success">
						<span class="input-group-prepend p-2 bg-dark text-light" style k,g="min-width: 2.25rem;"><i class="fas fa-lock prefix"></i></span>
						<input type="password" id="newPassword2" name=" newPassword2"  class="form-control border-0 validate" placeholder="Kennwort wiederholen" autocomplete="off">
						<div class="input-group-append p-2 bg-secondary"  style="min-width: 2.25rem;">
							<span class="fas fa-check text-success form-control-feedback invisible"></span>
							<span class="fa fa-warning text-warning form-control-feedback invisible"></span>
							<span class="fa fa-remove text-danger form-control-feedback invisible"></span>
						</div>
					</div>

					<div class="text-center form-sm mt-2">
						<button class="btn btn-primary"><i class="fas fa-save ml-1"></i> Speichern <i class="fas fa-sign-in ml-1"></i></button>
					</div>

				</div>

				</form>

				<!--Footer-->
				<div class="modal-footer">
					<div class="options text-center text-md-right mt-1">
						<p><a data-toggle="tab" href="#panel6" role="tab">Profil ändern</a></p>
						<p><a href="#" onclick='$("#logintab9").tab("show");' class="text-primary">Kennwort vergessen?</a></p>
					</div>

					<button type="button" class="btn btn-outline-primary waves-effect ml-auto" data-dismiss="modal">Schliessen</button>
				</div>
		</div>
		<!--/.Panel 9-->

<?php else: ?>


		  <!--Panel 7 Login
	___________________________________________________ -->
		  <div class="tab-pane fade in show active" id="panel7" role="tabpanel">

			<!--Body-->
			<form action="authentication.php" method="post">
				<div class="modal-body mb-1">

					<div class="input-group mb-3">
						<div class="input-group-prepend bg-secondary p-2">
							<i class="far fa-envelope"></i>
						</div>
						<input type="email" id="login-email" name="login-email" class="form-control validate" value='<? if (isset($_COOKIE["wlmonitor_username"])) { echo ($_COOKIE["wlmonitor_username"]);} ?>' placeholder="Ihre Mail Adresse" autocomplete="email" required>
						<label data-error="wrong" data-success="right" for="login-email"></label>
					</div>


					<div class="input-group mb-3">
						<div class="input-group-prepend bg-secondary p-2">
							<i class="fas fa-lock"></i>
						</div>
						<input type="password" id="login-password" name="login-password" class="form-control required" placeholder="Ihr Kennwort"  autocomplete="off" required>
						<label data-error="wrong" data-success="right" for="login-password"></label>
					</div>

					<div class="input-group mb-1">
						<div class="form-check ml-5">
							<label class="form-check-label">
								<input type="checkbox" class="form-check-input" id="keepLoggedin" name="stayLoggedin" value=1 <? if (isset($_COOKIE["wlmonitor_username"])) { echo ("CHECKED");} ?> >Angemeldet bleiben
								<button class="btn btn-sm btn-outline-dark border-0 p-0 m-0 ml-1"  title="Wenn Sie den Browser schliessen und wieder öffnen, merkt sich der Monitor Ihr Login." data-toggle="tooltip" data-placement="right">&#9432; </button></p>
							</label>
						</div>
					</div>

					<div class="input-group mb-3">
						<div class="form-check ml-5">
							<label class="form-check-label">
								<input type="checkbox" class="form-check-input" id="rememberName" name="rememberName" value=1 <? if (isset($_COOKIE["wlmonitor_username"])) { echo ("CHECKED");} ?> >Anmeldename merken
								<button class="btn btn-sm btn-outline-dark border-0 p-0 m-0 ml-1"  title="Wenn Sie sich ab- und wieder anmelden, bleibt das Feld für den Usernamen ausgefüllt." data-toggle="tooltip" data-placement="right">&#9432; </button></p>
							</label>
						</div>
					</div>

					<div class="text-center mt-2">
						<button class="btn btn-primary" type="submit" value="login">Anmelden<i class="fas fa-sign-in ml-1"></i></button>
					</div>

					<div class="text-left my-5">
						<div class="float-left">Cookies
							<button class="btn btn-outline-dark btn-sm border-0 p-0 m-0 ml-1"  title="Wenn Sie die Cookies löschen, werden Sie abgemeldet und zur Station Stephansplatz gebracht." data-toggle="tooltip" data-placement="right">&#9432; </button>
						</div>
						<div class="float-right">
							<button class="btn btn-outline-danger" onclick = 'removeCookies();location.reload();'>Cookies löschen</button>
							<pre class="cookieList"></pre>
						</div>

					</div>
				</div>

			<!--Footer-->
			<div class="modal-footer">
			  <div class="options text-center text-md-right mt-1">
				<p>Noch kein User? <a href="#" onclick='$("#logintab8").tab("show");' class="font-weight-bold text-primary">Registrieren</a></p>
				<p><a href="#" onclick='$("#logintab9").tab("show");' class="text-primary">Kennwort vergessen?</a></p>
			  </div>
			  <button type="button" class="btn btn-outline-primary waves-effect ml-auto" data-dismiss="modal">Schliessen</button>
			</div>
			</form>
		  </div>
		  <!--/.Panel 7-->


^		  <!--Panel 8 Register
	___________________________________________________ -->
		  <div class="tab-pane fade" id="panel8" role="tabpanel">

			<!--Body-->
			<form action="registration.php" method="post">
				<div class="modal-body">

					<div class="input-group mb-3">
						<div class="input-group-prepend bg-secondary p-2">
							<i class="fas fa-user"></i>
						</div>
						<input type="text" id="username" name="username" class="form-control required" placeholder="Name" autocomplete="name" required>
						<label data-error="wrong" data-success="right" for="username"></label>
					</div>


					<div class="input-group mb-3">
						<div class="input-group-prepend bg-secondary p-2">
							<i class="far fa-envelope"></i>
						</div>
						<input type="email" id="reg-email" name="reg-email" class="form-control validate" placeholder="Mail" autocomplete="email" required>
						<label data-error="wrong" data-success="right" for="reg-email"></label>
					</div>


					<div class="input-group mb-3">
						<div class="input-group-prepend bg-secondary p-2">
							<i class="fas fa-lock"></i>
						</div>
						<input type="password" id="reg-password" name="reg-password" class="form-control required" placeholder="Kennwort (A-Z, a-z, 0-9)" pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}" title="mind. 8 Zeichen, 1 Großbuchstabe, 1 Nummer"  autocomplete="off" required>
						<label data-error="wrong" data-success="right" for="reg-password"></label>
					</div>

					<div class="input-group mb-3">
						<div class="input-group-prepend bg-secondary p-2">
							<i class="fas fa-lock"></i>
						</div>
						<input type="password" id="password-1" name="password-1" class="form-control required" placeholder="Kennwort wiederholen." pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}" title="Die Kennwörter müssen übereinstimmen." autocomplete="off" required>
						<label data-error="wrong" data-success="right" for="password-1"></label>
					</div>


					<div class="form-sm mt-2 small" style="line-height: 13pt;">

						<p>Wir verwenden Cookies nur, um Ihre zuletzt gewählte Station zu speichern. Cookies werden auf Ihrem Rechner gespeichert, wir bekommen die niemals zu Gesicht.</p>

						<p>Cookies mit Ihren Einstellungen bleiben drei Tage aktuell und werden dann automatisch gelöscht. Ihr ok, dass wir Cookies verwenden,  bleibt 365 Tage in Ihrem Browser gespeichert. </p>

						<p>Sofern Sie sich als Benutzer des Systems registrieren, bekommen Sie die Möglichkeit Favoriten anzulegen. Wir speichern dafür den von Ihnen bekannt gegebenen Namen, die Mail-Adresse und einen Passwort-hash. Das heisst wir können feststellen, ob Ihr Passwort richtig ist, kennen das Passwort selbst aber nicht. </p>

						<p>Registrierung, Logins, Logouts und Vorgänge bei der Favoriten-Verwaltung werden in einem "Logbuch" gespeichert, das Sie sich in Ihrem Profil jederzeit ansehen können. Die Eintragungen des Logbuchs planen wir nicht länger als 360 Tage aufzubewahren und ca. einmal im Jahr zu löschen. Ihre Registrierungsdaten heben wir maximal 7 Jahre nach Beendigung Ihrer Registrierung oder nachdem wir dieses Service eingestellen zu Dokumentationszwecken auf.</p>

						<p>Dieses Service wird von uns widerruflich, ohne Entgelt oder Gewinnabsicht, auf rein freiwilliger und unverbindlicher Basis zur Verfügung gestellt. Für die Richtigkeit der Daten übernehmen wir keine wie immer geartete Gewähr.</p>

						<p>Ihre Daten werden zu keinen anderen Zweck als zur Bereitstellung dieses Services verarbeitet und werden an keine Dritten weitergegeben.	</p>

						<p class="border border-secondary text-body p-3 rounded"><label class="checkbox-inline"><input type="checkbox" name='DSGVO-ok' id='DSGVO-ok'> Mit Absenden Ihrer Registrierungsanforderung erklären Sie sich mit dieser Erklärung einverstanden.</label></p>

						<button type='submit' class='btn btn-primary mx-auto' id="regSubmit" value='register' disabled='disabled'>Registrieren <i class="fas fa-sign-in ml-1"></i></button>
						<br />

					</div>
			</form>
			</div>
			<!--Footer-->
			<div class="modal-footer">
			  <div class="options text-right">
				<p class="pt-1">Sie haben schon einen User? <a  href="#" onclick='$("#logintab7").tab("show");' class="font-weight-bold text-primary">Anmelden</a></p>
			  </div>
			  <button type="button" class="btn btn-outline-primary waves-effect ml-auto" data-dismiss="modal">Schliessen</button>
			</div>
		  </div>
		  <!--/.Panel 8-->

<?php endif ?>

		</div>
	  </div>
	</div>
	<!--/.Content-->
  </div>
</div>



	<!--
	Send Cookie ok Question
	-->
	<div id="cookieConsent" class="modal fade" role="dialog" tabindex="-1" z-index="99999" aria-hidden="true">
		<div class="modal-dialog modal-lg " style="bottom:0;">
			<!--Content-->
			<div class="modal-content">
				
				<!-- Modal Header -->
				<div class="modal-header bg-warning">
					<h5>Mögen Sie Kekse?</h5>
					<button type="button" class="close" data-dismiss="modal">&times;</button>
				</div>
				
				<!--Body-->
				<div class="modal-body d-flex justify-content-center ml-2">

					<div style="font-size:32pt;"> &#x1F36A;</div>
					
					<div class="flex-grow-1">
						<p class="">Wir benützen Cookies um Ihre letzten Einstellungen <b>auf Ihrem</b> Computer zu speichern.</p>
					
						<p> <a href="https://cookiesandyou.com/" target="_blank">Mehr dazu</a></p>
					
						<button type="button" id="acceptCookies" class="acceptcookies btn btn-primary btn-sm mx-auto" aria-label="Schliessen" data-dismiss="modal">
							Das ist ok.
						</button>
					</div>

				</div>
			</div>
		</div>
	</div>



	<!--
	+----------------------------------
	|
	|  Scripts
	|
	+----------------------------------


	load login status from php to js
	=======================================================================================
	
	-->
	<script>
		let userName = <?= json_encode($_SESSION['username'] ?? '') ?>;
		let userID = <?= json_encode($_SESSION['id'] ?? 1) ?>;
		userLoggedin = 0;
		let debug = 0;
		let rbl = 1718;
	</script>



	<!-- jQuery library -->
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>

	<!-- Popper JS -->
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>

	<!-- Latest compiled JavaScript -->
	<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>

	<!-- JQuery Plugin zum Validieren von Formularen -->
	<script src="https://ajax.aspnetcdn.com/ajax/jquery.validate/1.11.1/jquery.validate.min.js"></script>
	
	<!-- Rubberband Refresh -->
	<script src="js/xpull/xpull.js"></script>

	<script src="js/wl-monitor.js"></script>

	
	<script>
	// 	As soon as the document is ready loading and rendered, get the monitor and the buttons
	// =======================================================================================
		$(document).ready(function(){
			
			// Theme Management
			// load theme function into radio
			$('input[name=themePreference]').change(function(){
				var newTheme = changeTheme();
				setCookie("theme", newTheme, "365");
				console.log("Changing Theme on Demand to " + newTheme);
			});

			// Set Theme to selected option
			console.log("Theme Cookie is " + getCookie("theme"));
			if (getCookie("theme")) {
				$('#theme' + getCookie("theme")).attr("checked", true);
			} else {
				setCookie("theme", "auto", "365");
				$('#themeauto').attr("checked", true);
			}
			changeTheme();
			
			// Initialize the delay Tooltip
            $('[data-toggle="tooltip"]').tooltip();   

            // document.addEventListener('touchstart', handle, {passive: true});

			// Get monitors, favourites and station list
			// =========================================================
			if (GetURLParameter	('rbl'))	{rbl = GetURLParameter('rbl')
			} else 							{rbl = checkCookie();
			}

			// load monitor
			getMonitor(rbl);


			// load buttons
			getFavorites();


			// load station list
			getStationsAlpha();


			// Initialise UI
			// ================================

			// Initialise button group station search sort order
			$( "#stationSortorderDist" ).on("click",
				function() {

					// Get Geo-Location
					var options = {
						enableHighAccuracy: true,
						timeout: 5000,
						maximumAge: 500
					};

					if (navigator.geolocation) {
						navigator.geolocation.getCurrentPosition(getStationsDist, positionError, options);
					} else {
						sendAlert("Geolocation is not supported by this browser.", "warning");
						console.log("Geolocation is not supported by the browser of " . $_session["username"]);
					}
				});
				
			$( "#stationSortorderAlpha" ).on("click",
				function() {
					getStationsAlpha();
				});
				
			$( "#stationSortorderSearch" ).on("click",
				function() {
					getStationsSearch();
				});
				
			
						
			// Activate menus, logo and help
			$("#logo").click(function(){window.location.reload()});
			$("#helpListe").load("docu/hilfe.snippet");
			$("#docuContent").load("docu/readme.snippet");

			$('[data-toggle="popover"]').popover();
			$('[data-toggle="tooltip"]').tooltip();

			// Activate help search box
			$("#helpFilter").on("keyup", function() {
				if(debug){console.log("Help Search Key entered.")};
				
				var value = $(this).val().toLowerCase();
				
				$("#helpListe p").filter(function() {
					$(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
				});
			});
			
			
			// Activate Submit Button of Register Form only, when DSGVO-ok is checked
			$('#DSGVO-ok').change(function() {
				var DSGVOok;
				DSGVOok = !$(this).is(":checked");
				$("#regSubmit").prop('disabled', DSGVOok);
			});
			
			
			
			// Validate Change Password Form
			// -----------------------------------
			$("#changePassword").validate({
				rules: {
					oldPassword: {
						required: true,
						message: "Bitte geben Sie Ihr derzeitiges Kennwort ein!"
					},
					newPassword1: {
						required: true,
						minlength: 8,
						regexp: {
							regexp: /(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}/,
							message: "Das Kennwort muss mindestens 8 Zeichen, eine Großbuchstaben und 1 Zahl beinhalten!"
							}
						},
					newPassword2: {
						identical: {
							field: 'newPassword1',
							message: 'Die neuen Kennwörter stimmen nicht überein!'
						}
					}
				},
				messages: {
					newPassword1: {
						required: "Bitte geben Sie ein neues Kennwort ein",
						minlength: "Das Kennwort muss mindestens 8 Zeichen beinhalten"
						}
				},
				submitHandler: function(form) {
					// do things for a valid form
					form.submit;
				},
				success: function(){
					$("#changePassword .text-success").removeClass("invisible");
				},
				invalidHandler: function(event, validator) {
					var errors = validator.numberOfInvalids();
					if (errors) {
						var message = errors == 1
							? "Es gibt einen Fehler. Das betroffene Feld wurde markiert."
							: "Es gibt "+ errors +" Fehler. Die betroffenen Felder wurden markiert.";
						$("#changePasswordError").html(message);
						$("#changePasswordError").show();
					} else {
						$("#changePasswordError").hide();
					}
				},
			});
			
			
<? if ($loggedIn): ?>
			$('.cookieList').append(listCookies());
			$('#profileCookies').popover({container: listCookies()});
<? endif ?>

<?php
			// Add user menue
			echo "$('#userProfile').html(\"<a href='' class='nav-link text-dark' data-toggle='modal' data-target='#modalUser'><i class='fas fa-user-circle'></i></a>\");\n\n";
			
			
			// Check for alerts
			// ----------------------------------------------------------------------------------------
			if (isset($_SESSION['Error'])) {
				echo("sendAlert('".$_SESSION['Error']."', 'danger');");
				unset($_SESSION['Error']);
			}

			if (isset($_SESSION['Success'])) {
				echo("sendAlert('".$_SESSION['success']."', 'success');");
				unset($_SESSION['Success']);
			}

			if (isset($_SESSION['Notice'])) {
				echo("sendAlert('".$_SESSION['Notice']."', 'secondary');");
				unset($_SESSION['Notice']);
			}
			
			$_SESSION['invalidLogins'] = ($_SESSION['invalidLogins'] ?? 0);
			$_SESSION['loggedin'] = ($_SESSION['loggedin'] ?? 0);
			if ( ($_SESSION['invalidLogins'] > 0) && $_SESSION['loggedin'] ) {
				echo("sendAlert('<b>Warnung!</b> Es gab ".$_SESSION['invalidLogins'] . " ungültige Loginversuche.', 'warning');");
				$_SESSION['invalidLogins']=0;
			}

			if (isset($_SESSION['alerts'])) {

				foreach($_SESSION['alerts'] as $row) {
					$color = $row[0];
					$message = str_replace("\n", "<br />", htmlentities($row[1]));
					echo("sendAlert('$message', '$color');\n");
				}

				$_SESSION['alerts'] = array();
			}
			
			
			
			
			//output session if in debug mode
			$_SESSION['debug'] = ($_SESSION['debug'] ?? 0);
			if ($_SESSION['debug']) {
				$arr = get_defined_vars();
				$out  = "<p id='debugHeader'><b>Debug</b></p>\n";
				$out .= "<pre id='debugBody'>\n ";
				$out .= htmlentities(print_r($arr, true));
				$out .= "</pre>";

				echo "\nvar debugOut = ` \n";
				echo $out;
				echo "`;\n";

				echo "$('body').append(debugOut);\n";

				echo "$('#debugBody').append('Cookies ');\n";
				echo "$('#debugBody').append(listCookies());\n";

			}
?>
			loadLog();
			
			
			$('#content').xpull({
				'callback':function(){
					reloadMonitor() ;
					console.log('Released...')}
				});

			
			
			//Cookie Consent?
			if (getCookie("acceptCookies") == "") {
				
				$("#acceptCookies").click(function(){setCookie("acceptCookies", true, 365);});
				$('#cookieConsent').modal({backdrop:"static"});
			}
			
			$("#searchForm").submit(function(e){
			
				e.preventDefault();

				var apiurl = 'monitor.php?s='+$("#s").val();
				
				// Loading Spinner
				$("#monitor").html('<div class="spinner"><span class="spinner-border spinner-border-sm"></span> Abfahrtsdaten werden geladen ...</div>');
				
				// get readymade html from php script
				$("#monitor").load(apiurl);
		
				return false;
			
			});
		
		}); // $(document).ready




		// Functions
		// =================================
		
		// set new cookie for the station number rbl and reload the monitor
		
		// Initialize SearchFilter
		function initializeFilter() {
			
			$("#stationFilter").on("keyup", function() {
				console.log("Search Key entered.");
				var value = $(this).val().toLowerCase();
				
				$("#stationList li").filter(function() {
					$(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
				});
			});
		}
		
		function adf(stationName, stationRbls, colorClass) {
						
			$.ajax({
				url: "addFavorite.php?title=" + stationName + "&rbls=" + stationRbls + "&bclass=" + colorClass,
				success: function(result) {
					location.reload();
					}
			});
		};
						
		function changeMonitor(rbl, id) {
		
			if (rbl == undefined) {rbl=4111}
			if (id == undefined) {id=1}
			
			if(debug){console.log("Change rbl to " + rbl + ' for ' + id)};
			
			// reset automatic monitor refresh
			if (document.cookie.indexOf("monitorTimerID=") >= 0) {
				var monitorTimerID = getCookie("monitorTimerID");
				clearInterval(monitorTimerID);
			}
			// clearing all intervals
			var interval_id = window.setInterval("", 9999); // Get a reference to the last
															// interval +1
			for (var i = 1; i < interval_id; i++)
				window.clearInterval(i);
			
			monitorTimerID = setInterval(function(){reloadMonitor();},20000);
			setCookie("rbl", rbl, 3);
			setCookie("monitorTimerID", monitorTimerID, 3);
				
			getMonitor(rbl, id);
			
		}


		function getMonitor(rbl, id) {
			// you can call index with a rbl parameter; but there are problems getting rid of this parameter afterwards …
// 			if ((rbl==undefined) && (GetURLParameter('rbl') != ""))	{rbl = GetURLParameter('rbl'); if(debug){console.log("URL: Get Monitor for rbl " + rbl)};}
			if (rbl == undefined) 										{rbl = getCookie("rbl"); if(debug){console.log("Cookie_: Get Monitor for rbl " + rbl)};}
			if (rbl == undefined) 										{rbl = 4111; if(debug){console.log("Default: Get Monitor for rbl " + rbl)};}
			
			if (id == undefined) 										{id = userID}
			var apiurl = 'monitor.php?rbl='+rbl+'&id='+id;
			
			// Loading Spinner
			$("#monitor").html('<div class="spinner"><span class="spinner-border spinner-border-sm"></span> Abfahrtsdaten werden geladen ...</div>');
			
			// get readymade html from php script
			$("#monitor").load(apiurl);
			
		}

		function reloadMonitor() {
			
			var apiurl = 'monitor.php?id='+userID;
			
			// get readymade html from php script
			$("#monitor").load(apiurl);
			
		}



		function addButton(title, rbl, bclass){
			$("#buttons").append('<button onclick="changeMonitor(\''+rbl+'\');" type="button" class="btn '+bclass+' btn-block">'+title+'</button>');
		}


		function getFavorites() {
			
			// delete current list of buttons
			if(debug){console.log("Deleting buttons...")};
			$("#buttons").html("");
			
			// Fetch favourite stations from database (readymade html)
			if(debug){console.log("Fetching buttons from database...")};
			
			// try to load buttons from database
			
			$.ajax({
				async: false,
				cache: false,
				url : "getFavorites.php",
				timeout: 2000,
				success: function (data) {
					$("#buttons").html(data);
				},
				error: function (xhr,status,error) {
					if (debug)	{console.log('Error loading Favorites: '+status +': '+ error)};
					$('#buttons').append('<div class="alarm alarm-danger">Fehler: '+status +': '+ error +'</div>');
				}
			});

			// build favourite buttons from local array
			/* if(debug){console.log("Rendering favourite buttons...")};
			var i;
			for (i in jsonButtons) {
				addButton( jsonButtons[i].title, jsonButtons[i].rbl, jsonButtons[i].bclass);
			}
			*/
			if(debug){console.log("Done favourite buttons.")};
			
			
			// 	Activate Save Function after re-sorting
			$("#btnSaveFavorites").click(function(){
				
				if(debug){console.log('Loading favorites order...')};
				
				var listItems = $("#buttons button");
				var i = 0;
				var sortArray = "[";
				
				listItems.each(function(idx, button) {
					i++;
					var htmlBtnID = $(button).attr('id');
					var btnID = htmlBtnID.split("-");
					if (i > 1) sortArray += ", ";
					sortArray += "('sort':" + i + ",'id':" + btnID[1] + ")";
				});
				sortArray += "]";
				
				// send sql
				if(debug){console.log("Trying to save Favorites.")};
				if(debug){console.log(sortArray)};
				
				$.ajax({
					type:"POST",
					cache:false,
					url:"saveFavorites.php",
					data:{sortArray: sortArray},	// multiple data sent using ajax
					timeout: 2000,
					success: (function (html) {
						$('#frmSaveFavorites').prepend(html);
						if(debug){console.log("Favorites saved.")};
					}),
					error:( function (xhr,status,error) {
						if(debug){console.log('Error saving Favorites: '+status +': '+ error)};
						$('#frmSaveFavorites').prepend('<div class="alarm alarm-danger">Fehler: '+status +': '+ error +'</div>');
					})
				})
			});
		}
		
		
		// if geo location can't be retrieved, fill search filter alpabetically
		function positionError(error) {
			console.warn('ERROR (' + error.code + '): ' + error.message);
			
			switch(error.code) {
				case error.PERMISSION_DENIED:
					sendAlert("User denied the request for Geolocation.", "warning");
					break;
				case error.POSITION_UNAVAILABLE:
					sendAlert("Location information is unavailable.", "warning");
					break;
				case error.TIMEOUT:
					sendAlert("The request to get user location timed out.", "warning");
					break;
				case error.UNKNOWN_ERROR:
					sendAlert("An unknown error occurred.", "warning");
					break;
			}
		
			getStationsAlpha();
		}
		
		
		function getStationsSearch() {
			$("#stationFilter").addClass("d-none");
			$("#s").removeClass("d-none");
			
			$("#searchForm").preventDefault();
			
			$("#stationList").html("")
			$("#stationList").addClass("d-none");

		}
		
				
		function getStationsDist(myPosition) {
			
			$("#stationSortDist").removeClass("d-none");
			$("#stationFilter").removeClass("d-none");
			$("#s").addClass("d-none");

			$("#stationFilter").attr("placeholder", "in der Nähe suchen");
			
			console.log("Position acquired: " + myPosition.coords.latitude + ", " + myPosition.coords.longitude);
			$.post("savePosition.php", {lat:myPosition.coords.latitude, lon:myPosition.coords.longitude});
			
			sendAlert("Ihre Position: (" + myPosition.coords.latitude + ", " + myPosition.coords.longitude + ")", "secondary");
			
			$.getJSON("getStations.php",
				{ "lat":myPosition.coords.latitude, "lon":myPosition.coords.longitude },
				function(responseTxt, statusTxt, xhr){
					
					if (statusTxt == "success") {
						console.log("Data transferred.");
						
						$("#stationList").html("");
						
						$.each(responseTxt, function(i, row){
							dist = row.distance
							if (dist > 1000) {
								dist = parseFloat(dist/1000).toFixed(2) + ' km';
							} else {
								dist = parseFloat(dist).toFixed(0) + ' m';
							}
 							$("#stationList").append('<li class="mb-xs-1 mb-md-0"><a target="wlmonitor" href="https://www.google.com/maps/dir/?api=1&origin=' + myPosition.coords.latitude + ',' + myPosition.coords.longitude + '&destination=' + row.lat + ',' + row.lon + '&travelmode=walking"><i class="fas fa-location-arrow mr-3"></i></a> <span onclick="changeMonitor(\'' + row.rbls + '\',' + userID + ');" >' + row.station + '</span> (' + dist + ') </li>\n');
						});
					}
					
					$("#stationSortDist").addClass("d-none");
					if (statusTxt == "error")
						console.log("Error: " + xhr.status + ": " + xhr.statusText);
				}
			);
			
			initializeFilter;
		}
		
		function getStationsAlpha() {
		
			$("#stationFilter").removeClass("d-none");
			$("#s").addClass("d-none");
			$("#stationFilter").attr("placeholder", "A-Z suchen");
			
			$("#stationSortAlpha").removeClass("d-none");
			
			$.getJSON("getStations.php",
				function(responseTxt, statusTxt, xhr){
					
					if (statusTxt == "success") {
						console.log("Data transferred.");
						
						$("#stationList").html("");
						
						$.each(responseTxt, function(i, row){
							$("#stationList").append('<li class="mb-xs-1 mb-md-0" onclick="changeMonitor(\'' + row.rbls + '\',' + userID + ');" >' + row.station + '</li>\n');
						});
					}
					
					$("#stationSortAlpha").addClass("d-none");
					
					if (statusTxt == "error")
						console.log("Error: " + xhr.status + ": " + xhr.statusText);
				}
			);
			
			initializeFilter;
		}
		
		
		// load Log
		function loadLog(Page=1,Limit=20) {
			
			var interval = 10;
			var logPage = ((typeof Page == 'undefined') ? <? echo($_SESSION["logPage"]); ?> : Page) ;
			
			var logLimit = typeof Limit !== 'undefined' ? $('#limit-records').val() : Limit;
			$("#limit-records option[selected='selected']").attr('selected', '');
			$("#limit-records option[value="+logLimit+"]").attr('selected', 'selected');
			
			if (logPage == 1) {	var logStart = 1;}
			else {				var logStart = (logPage - 1) * logLimit;}
			var logNext = logPage + 1;
			var logPrev = logPage - 1;
			$('#logPagination').html("");
			
<?php
			// 	How many log entries does the user have?
			$sql = "SELECT count(log.id) as total FROM wl_log as log WHERE log.idUser = ? ";
			$stmt = $con->prepare($sql);
			$stmt->bind_param('i', $_SESSION['id']);
			$stmt->execute();
			$stmt->bind_result($logTotal);
			$stmt->fetch();
			
			echo "var logTotal = " . (empty($logTotal) ? 0 : $logTotal). ";\n";
			echo "var logPages = Math.ceil(logTotal/logLimit );\n";
			
			$stmt->free_result();
?>
			
			if (logPage > 1) {
			$('#logPagination').append("<li class='page-item'><a href='#' class='btn page-link' onclick='loadLog(" + logPrev + ", " + logLimit + ")' aria-label='Previous'><span aria-hidden='true'>&laquo;</span> <span class='sr-only'> Zurück</span></a></li>");
			}
			
			if (logPage > ((interval/2)+1)) {
			$('#logPagination').append("<li class='page-item'><a href='#' class='page-link' onclick='loadLog(1, " + logLimit + ")'>1</a></li>");
			$('#logPagination').append("<li class='page-item'> … </li>");
			}
			
			for(i = 1; i<=logPages; i++) {
				if (i>(logPage-(interval/2)) && i<(logPage+(interval/2))) {
					if (i==logPage) $('#logPagination').append("<li class='page-item active'><a href='#' class='page-link'>" + i + "</a></li>");
					else 			$('#logPagination').append("<li class='page-item'><a href='#' class='page-link' onclick='loadLog(" + i + ", " + logLimit + ")'>" + i + "</a></li>");
				}
			}
			
			if (logPage < Math.floor(logPages-(interval/2))) {
				$('#logPagination').append("<li class='page-item'> … </li>");
			}
			
			if (logPage < Math.floor(logPages-(interval/2))+1) {
				$('#logPagination').append("<li class='page-item'><a href='#' class='page-link' onclick='loadLog(" + logPages + ", " + logLimit + ")'>" + logPages + "</a></li>");
			}
			
			if (logPage < logPages) {
			$('#logPagination').append("<li class='page-item'><a href='#' class='btn page-link' onclick='loadLog(" + logNext + ", " + logLimit + ")' aria-label='Next'><span aria-hidden='true'>&raquo;</span> <span class='sr-only'>Weiter</span></a></li>");
			}
			
			$.getJSON("getLog.php",
				{ "logLimit":logLimit, "logPage":logPage, "userId": userID },
				function(responseTxt, statusTxt, xhr){
					
					if (statusTxt == "success") {
						console.log("Data transferred.");
						
						$("#logTable").html("");
						
						$.each(responseTxt, function(i, row){
							dist = row.distance
							if (dist > 1000) {
								dist = parseFloat(dist/1000).toFixed(2) + ' km';
							} else {
								dist = parseFloat(dist).toFixed(0) + ' m';
							}
								$("#logTable").append('<tr><td>' + row.logTime + '</td><td>' + row.context + '</td> <td>' + row.activity + '</td> <td>' + row.origin + '</td> <td>' + row.ipAdress + '</td> </tr>\n');
						});
						
					}
				});
		}
	</script>
	
	
	<?php
		// $stmt->close();
	?>
	
</body>
</html>




