<?php

// Global Constants
define("SCRIPT_PATH", '/home/.sites/765/site679/web/jardyx.com/wlmonitor/');
define("CURRENT_PATH", __FILE__);
define("AVATAR_DIR", "img/user/");
date_default_timezone_set('Europe/Vienna');
define("APIKEY", 'tVqqssNTeDyFb35');
define("MAX_DEPARTURES", 2);
define("DATABASE_HOST", 'mysqlsvr78.world4you.com');
define("DATABASE_USER", 'sql6675098');
define("DATABASE_PASS", 'dr@3ysr');
define("DATABASE_NAME", '5279249db19');


// Database Connection
function createDBConnection() {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $con = mysqli_connect(DATABASE_HOST, DATABASE_USER, DATABASE_PASS, DATABASE_NAME);
    mysqli_set_charset($con, 'utf8');

    if (mysqli_connect_errno()) {
        die('Failed to connect to MySQL: ' . mysqli_connect_error());
    }
    return $con;
}

// Manage User Session
function manageUserSession() {
    session_start(['cookie_lifetime' => 60 * 60 * 24 * 4]);
    $sId = $_SESSION["sId"] ?? "";

    if ($sId == "") {

        if (isset($_COOKIE['sId']) && preg_match('/^[a-zA-Z0-9,\-]{22,128}$/', $_COOKIE['sId'])) {
            $sId = $_COOKIE['sId'];
			session_abort();
            session_id($sId);
			session_start(['cookie_lifetime' => 60 * 60 * 24 * 4]);
            addAlert('warning', 'Session recovered');
        } else {
            $sId = session_id();
            addAlert('warning', 'New Session.');
            setCookie("theme", "auto", time() + 60 * 60 * 24 * 365);
			$img = $_SESSION['img'] = "img/user-md-grey.svg";
        }
        setcookie('sId', $sId, time() + 60 * 60 * 24 * 4); // Refresh cookie
        $_SESSION['sId'] = $sId;
    }

    $_SESSION["logPage"] ??= 1;
    $_SESSION["logLimit"] ??= 20;
    return $sId;
}

// Append Log Entry
function appendLog($con, $context = "*", $activity = "*", $origin = "web") {
    $sql = "INSERT INTO wl_log (idUser, context, activity, origin, ipAdress, logTime) VALUES (?, ?, ?, ?, INET_ATON(?), CURRENT_TIMESTAMP)";
    $stmt = $con->prepare($sql);
    $userIP = getUserIpAddr();
    $id = $_SESSION['id'] ?? 1;
    $stmt->bind_param('issss', $id, $context, $activity, $origin, $userIP);
    $stmt->execute();
    $stmt->close();
    return true;
}

// shortcut for append log entry
function logDebug($label, $message) {
    if (($_SESSION["debug"] ?? null)) {
        appendLog($con, $label, $message, 'web');
    }
}


// Get User IP Address
function getUserIpAddr() {
    return $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];
}

// Add Alert Message
function addAlert($messageType, $message) {
    $_SESSION['alerts'] = $_SESSION['alerts'] ?? [];
    array_push($_SESSION['alerts'], [$messageType, htmlentities($message)]);
}

function sanitizeRblInput($RblGet) {
    // Use regular expression to filter out non-digit and non-comma characters
    $filteredInput = preg_replace('/[^0-9,]/', '', $RblGet);
    
    return $filteredInput;
}



// Create a Database Connection
$con = createDBConnection();

// Manage User Session
$sId = manageUserSession();

$loggedIn = $_SESSION['loggedin'] ?? 0;
$username = $loggedIn ? $_SESSION['username'] : "";
$img = $_SESSION['img'] = $_SESSION['img'] ?? "user-md-grey.svg";
$avatarDir = $loggedIn ? AVATAR_DIR . $_SESSION['img'] : "";




?>
