<?php

$origins = array(
	'http://jamulus.softins.co.uk' => 1,
	'https://jamulus.softins.co.uk' => 1,
	'http://jamulus.softins.co.uk:8080' => 1,
	'http://explorer.jamulus.io' => 1,
	'https://explorer.jamulus.io' => 1,
	'http://explorer.jamulus.io:8080' => 1,
	'http://explorer.softins.co.uk' => 1,
	'https://explorer.softins.co.uk' => 1,
	'http://explorer.softins.co.uk:8080' => 1
);

if (isset($_SERVER['HTTP_ORIGIN'])) {
	$http_origin = $_SERVER['HTTP_ORIGIN'];

	if (array_key_exists($http_origin, $origins))
	{
		header("Access-Control-Allow-Origin: $http_origin");
		header("Vary: Origin");
	}
}

header('Content-Type: application/json');

readfile("http://13.42.109.202/jamulus-php-master/servers.php?" . $_SERVER['QUERY_STRING']);
?>
