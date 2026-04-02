<?php
/**
 * ajaxFav.php
 * 
 * WL Monitor Favourites Editor
 *
 *
 * Dependencies:
 *		- ajacrud.com
 *
 *
 * 
 * PHP version 7.2
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

if (($_SESSION['loggedin'] != TRUE) || ($_SESSION['rights'] != "Admin")){
		header('Location: index.php'); exit;
}


header('Content-Type: text/html; charset=utf-8');

// ---ajaxCrud---

// required file and class
require_once(__DIR__ . '/../include/ajaxCRUD/preheader.php');
include_once(__DIR__ . '/../include/ajaxCRUD/ajaxCRUD.class.php');


// AjaxCrud Definitions


// create an instance of the class
$tblAccounts = new ajaxCRUD("User", "wl_accounts", "id");

$tblAccounts ->omitPrimaryKey();

//if we only want to show a few of the fields in the table
$tblAccounts ->showOnly("username,email,disabled,departures,debug,rights");

$tblAccounts ->displayAs("username", "Benutzername");
$tblAccounts ->displayAs("img", "Ihr Bild");
$tblAccounts ->displayAs("img_type", "Bildtyp");
$tblAccounts ->displayAs("email", "E-Mail");
$tblAccounts ->displayAs("disabled", "deaktiviert");
$tblAccounts ->displayAs("departures", "Abfahrten");
$tblAccounts ->displayAs("debug", "debug");
$tblAccounts ->displayAs("rights", "Typ");


// define that a field if a checkbox
$tblAccounts ->defineCheckbox('disabled','deaktiviert','aktiv');
$tblAccounts ->defineCheckbox('debug','debug','produktiv');

// formatFieldWithFunction is a powerful method
$tblAccounts->formatFieldWithFunction('img', 'makeImg');

// $tblAccounts->defineRelationship("bclass", "wl_colors", "color", "farbe", "farbe", 1);

// set the number of rows to display (per page)
$tblAccounts ->setLimit(25);

// set a filter box at the top of the table
$tblAccounts ->addAjaxFilterBox('username', 10);

/*
// modify field with class
// for masking and for adding calendar widget
$tblAccounts->modifyFieldWithClass("fldDateMet", "datepicker");
$tblAccounts->modifyFieldWithClass("fldZip", "zip");
$tblAccounts->modifyFieldWithClass("fldPhone", "phone");
*/

// show CSV export button
$tblAccounts->showCSVExportOption();

// order the table by any field you want
$tblAccounts->addOrderBy("ORDER BY username");

$tblAccounts->actionText = "Aktionen";
$tblAccounts->addButtonText = "Hinzufügen";
$tblAccounts->addMessage = "Benutzer hinzugefügt";
$tblAccounts->cancelText = "Abbrechen";
$tblAccounts->saveText = "speichern";
$tblAccounts->addText = "Hinzufügen";
$tblAccounts->deleteText = "löschen";
$tblAccounts->hover_color = "#aa0000";


?><!DOCTYPE html>
<html lang="de">
<head>
	<title>User Editor</title>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta http-equiv="X-UA-Compatible" content="ie=edge">
	
	<link rel="shortcut icon" href="img/favicon-96x96.png">
	
	<!-- Font Awesome  -->	
	<link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.7.0/css/all.css" integrity="sha384-lZN37f5QGtY3VHgisS14W3ExzMWZxybE1SJSEsQp9S+oqd12jhcu+A56Ebc1zFSJ" crossorigin="anonymous">
	<!--script src="https://kit.fontawesome.com/175f395c88.js" crossorigin="anonymous"></script-->
	
	<!-- jQuery library -->
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
	
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
	
	<!-- Latest compiled JavaScript -->
	<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
	
	<!-- JQuery Plugin zum Validieren von Formularen -->
	<script src="https://ajax.aspnetcdn.com/ajax/jquery.validate/1.11.1/jquery.validate.min.js"></script>
	
	
	<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto|Roboto+Mono|Share+Tech+Mono">

	<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
	
	<link rel="stylesheet" href="style/dark/bootstrap.css">
	<link rel="stylesheet" href="style/wl-monitor.css">
</head>

<body>

<?php

$tblAccounts->showTable();


// ---validation.js---

// mask some fields with desired input mask
// $("input.phone").mask("(999) 999-9999");
// $("input.zip").mask("99999");

/*
//put a date picker on a field
$( ".datepicker" ).datepicker({
	dateFormat: 'yy-mm-dd',
	showOn: "button",
		buttonImage: "includes/images/calendar.gif",
		buttonImageOnly: true,
	onClose: function(){
		this.focus();
		//$(this).submit();
	}
});
*/
?>

	
	<script>
	
	$(document).ready(function(){

		$(".btn").addClass("btn-secondary");
		$(".btn").css("margin-bottom", "2px");
	});
	
	</script>
	

</body>
</html>
