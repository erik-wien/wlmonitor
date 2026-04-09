<?php

require_once(__DIR__ . '/../include/initialize.php');


	if (empty($_GET["logLimit"])) {
		$limit = 20;
	} else {
		$limit = $_GET["logLimit"];
	}
	$_SESSION["loglimit"] = $limit;
	
	if (empty($_GET["logPage"])) {
		$page  = 1;
	} else { 
		$page  = $_GET["logPage"];
	}
	$_SESSION["logPage"] = $page;
	
	$id		= empty($_GET["userId"])  ?  1 : $_GET["userId"];
	$start = ($page - 1) * $limit;
	
	
	
	// Get the total number of records
	$sql = "SELECT count(log.id) as total FROM wl_log as log WHERE log.idUser = ? ";
	$stmt = $con->prepare($sql);
	$stmt->bind_param('i', $id);
	$stmt->execute();
	$stmt->bind_result($total);
	$stmt->get_result();
	
	$pages = ceil( $total / $limit );
	$Previous = $page - 1;
	$Next = $page + 1;
	
	
// 	load log
	$sql = "SELECT log.`id`, log.`idUser`, log.`context`, log.`activity`, log.`origin`, INET_NTOA(log.`ipAdress`) as ipAdress, log.logTime as logTime FROM wl_log as log WHERE log.idUser = ? ORDER BY log.logTime DESC LIMIT ?, ?";
		
	$stmt = $con->prepare($sql);
	// Bind parameters (s = string, i = int, b = blob, etc), in our case the username is a string so we use "s"
	
	
	$stmt->bind_param('iii', $id, $start, $limit);
	$stmt->execute();
	
	$stmt->bind_result($id, $idUser,$context,$activity,$origin, $ipAdress,$logTime);

	$result = $stmt->get_result();
	$outp = $result->fetch_all(MYSQLI_ASSOC);
	
	if ($stmt->error) {
		json_encode($stmt->error . "\n"); 
	
	} else {
		echo json_encode($outp);
	}

	$stmt->close();
	


?>