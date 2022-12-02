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

// if running from command line for testing, copy args into $_GET
if (array_key_exists('argv', $_SERVER) && is_array($_SERVER['argv'])) {
	array_shift($_SERVER['argv']);	// discard filename

	foreach ($_SERVER['argv'] as $arg) {
		list($k,$v) = explode('=', $arg, 2);
		$_GET[$k] = $v;
	}
}

if (isset($_SERVER['HTTP_ORIGIN'])) {
	$http_origin = $_SERVER['HTTP_ORIGIN'];

	if (array_key_exists($http_origin, $origins))
	{
		header("Access-Control-Allow-Origin: $http_origin");
		header("Vary: Origin");
	}
}

header('Content-Type: application/json');

$pretty = isset($_GET['pretty']) ? 'p-' : '';

// backward compatibility
if (isset($_GET['central'])) {
	$_GET['directory'] = $_GET['central'];
	unset($_GET['central']);
}

if (isset($_GET['directory'])) {
	@list($host, $port) = explode(':', $_GET['directory']);
	$cachefile = '/tmp/directory-' . $pretty;
} elseif (isset($_GET['server'])) {
	@list($host, $port) = explode(':', $_GET['server']);
	$cachefile = '/tmp/server-' . $pretty;
} elseif (isset($_GET['query'])) {
	@list($host, $port) = explode(':', $_GET['query']);
  $cachefile = '/tmp/query-' . $pretty;
} else {
	echo '{"error":"No directory or server specified"}';	// send error message
	exit;
	//$_GET['directory'] = 'private.jamulus.io:22124';
}

if (isset($port)) {
	$port = (int)$port;
} else {
	$port = 22124;
}
$ip = gethostbyname($host);
$numip = ip2long($ip);

if ($numip === false) {
	echo '{"error":"Invalid hostname '.$host.'"}';	// send error message
	exit;
}

$done = false;
$listcomplete = false; // need to get the whole list before any other messages
$msgqueue = array();

// define the cache file
$cachefile .= $host.'-'.$port.'.json';
$cachetime = 10;	// 10 seconds - to keep up-to-date, but avoid multiple clients hammering servers

// and a temporary file for refreshing it - this will be created with exclusive lock to avoid multiple writers
$tmpfile = $cachefile.'.tmp';

function cleanup() {
	global $tmpfile;
	if (isset($tmpfile)) {
		unlink($tmpfile);	// cleanup temp file if we abort with an error
	}
}

$start = time();
for(;;) {
	// Serve from the cache if it is younger than $cachetime
	if (file_exists($cachefile) && time() < filemtime($cachefile) + $cachetime) {
		readfile($cachefile);
		// error_log(sprintf("Served cache for %s to %s", $_GET['directory'], $_SERVER['REMOTE_ADDR']));
		exit;
	}

	clearstatcache();	// make sure we don't cache the mtime

	// otherwise, try to create temp file for new data
	if ($tmp = @fopen($tmpfile, 'x'))
		break;  // we have the temp file, so fetch new data

	if (time() > $start + 20) {	// don't wait forever
		die("Can't obtain temporary file\n");
	}

	// wait 200ms and check cache file again (another request was building it)
	usleep(200000);
}

register_shutdown_function('cleanup');	// ensure temp file gets deleted if we abort

// error_log(sprintf("Fetching new data for %s to %s", $_GET['directory'], $_SERVER['REMOTE_ADDR']));

ob_start();	// start the output buffer

$servers = array();
$serverbyip = array();
$clientcount = 0;

// source ports to use
define('CLIENT_PORT', 22134);			// Use same default port as a real client
define('CLIENT_PORTS_TO_TRY', 16);		// Number of consecutive ports to try before giving up

// message IDs
define('ILLEGAL', 0);				// illegal ID
define('ACKN', 1);				// acknowledge
define('JITT_BUF_SIZE', 10);			// jitter buffer size
define('REQ_JITT_BUF_SIZE', 11);		// request jitter buffer size
define('NET_BLSI_FACTOR', 12);			// OLD (not used anymore)
define('CHANNEL_GAIN', 13);			// set channel gain for mix
define('CONN_CLIENTS_LIST_NAME', 14);		// OLD (not used anymore)
define('SERVER_FULL', 15);			// OLD (not used anymore)
define('REQ_CONN_CLIENTS_LIST', 16);		// request connected client list
define('CHANNEL_NAME', 17);			// OLD (not used anymore)
define('CHAT_TEXT', 18);			// contains a chat text
define('PING_MS', 19);				// OLD (not used anymore)
define('NETW_TRANSPORT_PROPS', 20);		// properties for network transport
define('REQ_NETW_TRANSPORT_PROPS', 21);		// request properties for network transport
define('DISCONNECTION', 22);			// OLD (not used anymore)
define('REQ_CHANNEL_INFOS', 23);		// request channel infos for fader tag
define('CONN_CLIENTS_LIST', 24);		// channel infos for connected clients
define('CHANNEL_INFOS', 25);			// set channel infos
define('OPUS_SUPPORTED', 26);			// tells that OPUS codec is supported
define('LICENCE_REQUIRED', 27);			// licence required
define('REQ_CHANNEL_LEVEL_LIST', 28);		// request the channel level list
define('VERSION_AND_OS', 29);	// version number and operating system
define('CHANNEL_PAN', 30);	// set channel pan for mix
define('MUTE_STATE_CHANGED', 31);	// mute state of your signal at another client has changed
define('CLIENT_ID', 32);	// current user ID and server status
define('RECORDER_STATE', 33);	// contains the state of the jam recorder (ERecorderState)
define('REQ_SPLIT_MESS_SUPPORT', 34); // request support for split messages
define('SPLIT_MESS_SUPPORTED', 35); // split messages are supported
define('CLM_START', 1000);			// start of connectionless messages
define('CLM_PING_MS', 1001);			// for measuring ping time
define('CLM_PING_MS_WITHNUMCLIENTS', 1002);	// for ping time and num. of clients info
define('CLM_SERVER_FULL', 1003);		// server full message
define('CLM_REGISTER_SERVER', 1004);		// register server
define('CLM_UNREGISTER_SERVER', 1005);		// unregister server
define('CLM_SERVER_LIST', 1006);		// server list
define('CLM_REQ_SERVER_LIST', 1007);		// request server list
define('CLM_SEND_EMPTY_MESSAGE', 1008);		// an empty message shall be send
define('CLM_EMPTY_MESSAGE', 1009);		// empty message
define('CLM_DISCONNECTION', 1010);		// disconnection
define('CLM_VERSION_AND_OS', 1011);		// version number and operating system
define('CLM_REQ_VERSION_AND_OS', 1012);		// request version number and operating system
define('CLM_CONN_CLIENTS_LIST', 1013);		// channel infos for connected clients
define('CLM_REQ_CONN_CLIENTS_LIST', 1014);	// request the connected clients list
define('CLM_CHANNEL_LEVEL_LIST', 1015);		// channel level list
define('CLM_REGISTER_SERVER_RESP', 1016);	// status of server registration request
define('CLM_REGISTER_SERVER_EX', 1017);	// register server with extended information
define('CLM_RED_SERVER_LIST', 1018); // reduced server list

$countries = array(
	0 => '-',
	1 => 'Afghanistan',
	2 => 'Albania',
	3 => 'Algeria',
	4 => 'American Samoa',
	5 => 'Andorra',
	6 => 'Angola',
	7 => 'Anguilla',
	8 => 'Antarctica',
	9 => 'Antigua And Barbuda',
	10 => 'Argentina',
	11 => 'Armenia',
	12 => 'Aruba',
	13 => 'Australia',
	14 => 'Austria',
	15 => 'Azerbaijan',
	16 => 'Bahamas',
	17 => 'Bahrain',
	18 => 'Bangladesh',
	19 => 'Barbados',
	20 => 'Belarus',
	21 => 'Belgium',
	22 => 'Belize',
	23 => 'Benin',
	24 => 'Bermuda',
	25 => 'Bhutan',
	26 => 'Bolivia',
	27 => 'Bosnia And Herzegowina',
	28 => 'Botswana',
	29 => 'Bouvet Island',
	30 => 'Brazil',
	31 => 'British Indian Ocean Territory',
	32 => 'Brunei',
	33 => 'Bulgaria',
	34 => 'Burkina Faso',
	35 => 'Burundi',
	36 => 'Cambodia',
	37 => 'Cameroon',
	38 => 'Canada',
	39 => 'Cape Verde',
	40 => 'Cayman Islands',
	41 => 'Central African Republic',
	42 => 'Chad',
	43 => 'Chile',
	44 => 'China',
	45 => 'Christmas Island',
	46 => 'Cocos Islands',
	47 => 'Colombia',
	48 => 'Comoros',
	49 => 'Congo Kinshasa',
	50 => 'Congo Brazzaville',
	51 => 'Cook Islands',
	52 => 'Costa Rica',
	53 => 'Ivory Coast',
	54 => 'Croatia',
	55 => 'Cuba',
	56 => 'Cyprus',
	57 => 'Czech Republic',
	58 => 'Denmark',
	59 => 'Djibouti',
	60 => 'Dominica',
	61 => 'Dominican Republic',
	62 => 'East Timor',
	63 => 'Ecuador',
	64 => 'Egypt',
	65 => 'El Salvador',
	66 => 'Equatorial Guinea',
	67 => 'Eritrea',
	68 => 'Estonia',
	69 => 'Ethiopia',
	70 => 'Falkland Islands',
	71 => 'Faroe Islands',
	72 => 'Fiji',
	73 => 'Finland',
	74 => 'France',
	75 => 'Guernsey',
	76 => 'French Guiana',
	77 => 'French Polynesia',
	78 => 'French Southern Territories',
	79 => 'Gabon',
	80 => 'Gambia',
	81 => 'Georgia',
	82 => 'Germany',
	83 => 'Ghana',
	84 => 'Gibraltar',
	85 => 'Greece',
	86 => 'Greenland',
	87 => 'Grenada',
	88 => 'Guadeloupe',
	89 => 'Guam',
	90 => 'Guatemala',
	91 => 'Guinea',
	92 => 'Guinea Bissau',
	93 => 'Guyana',
	94 => 'Haiti',
	95 => 'Heard And McDonald Islands',
	96 => 'Honduras',
	97 => 'Hong Kong',
	98 => 'Hungary',
	99 => 'Iceland',
	100 => 'India',
	101 => 'Indonesia',
	102 => 'Iran',
	103 => 'Iraq',
	104 => 'Ireland',
	105 => 'Israel',
	106 => 'Italy',
	107 => 'Jamaica',
	108 => 'Japan',
	109 => 'Jordan',
	110 => 'Kazakhstan',
	111 => 'Kenya',
	112 => 'Kiribati',
	113 => 'North Korea',
	114 => 'South Korea',
	115 => 'Kuwait',
	116 => 'Kyrgyzstan',
	117 => 'Laos',
	118 => 'Latvia',
	119 => 'Lebanon',
	120 => 'Lesotho',
	121 => 'Liberia',
	122 => 'Libya',
	123 => 'Liechtenstein',
	124 => 'Lithuania',
	125 => 'Luxembourg',
	126 => 'Macau',
	127 => 'Macedonia',
	128 => 'Madagascar',
	129 => 'Malawi',
	130 => 'Malaysia',
	131 => 'Maldives',
	132 => 'Mali',
	133 => 'Malta',
	134 => 'Marshall Islands',
	135 => 'Martinique',
	136 => 'Mauritania',
	137 => 'Mauritius',
	138 => 'Mayotte',
	139 => 'Mexico',
	140 => 'Micronesia',
	141 => 'Moldova',
	142 => 'Monaco',
	143 => 'Mongolia',
	144 => 'Montserrat',
	145 => 'Morocco',
	146 => 'Mozambique',
	147 => 'Myanmar',
	148 => 'Namibia',
	149 => 'Nauru Country',
	150 => 'Nepal',
	151 => 'Netherlands',
	152 => 'Cura Sao',
	153 => 'New Caledonia',
	154 => 'New Zealand',
	155 => 'Nicaragua',
	156 => 'Niger',
	157 => 'Nigeria',
	158 => 'Niue',
	159 => 'Norfolk Island',
	160 => 'Northern Mariana Islands',
	161 => 'Norway',
	162 => 'Oman',
	163 => 'Pakistan',
	164 => 'Palau',
	165 => 'Palestinian Territories',
	166 => 'Panama',
	167 => 'Papua New Guinea',
	168 => 'Paraguay',
	169 => 'Peru',
	170 => 'Philippines',
	171 => 'Pitcairn',
	172 => 'Poland',
	173 => 'Portugal',
	174 => 'Puerto Rico',
	175 => 'Qatar',
	176 => 'Reunion',
	177 => 'Romania',
	178 => 'Russia',
	179 => 'Rwanda',
	180 => 'Saint Kitts And Nevis',
	181 => 'Saint Lucia',
	182 => 'Saint Vincent And The Grenadines',
	183 => 'Samoa',
	184 => 'San Marino',
	185 => 'Sao Tome And Principe',
	186 => 'Saudi Arabia',
	187 => 'Senegal',
	188 => 'Seychelles',
	189 => 'Sierra Leone',
	190 => 'Singapore',
	191 => 'Slovakia',
	192 => 'Slovenia',
	193 => 'Solomon Islands',
	194 => 'Somalia',
	195 => 'South Africa',
	196 => 'South Georgia And The South Sandwich Islands',
	197 => 'Spain',
	198 => 'Sri Lanka',
	199 => 'Saint Helena',
	200 => 'Saint Pierre And Miquelon',
	201 => 'Sudan',
	202 => 'Suriname',
	203 => 'Svalbard And Jan Mayen Islands',
	204 => 'Swaziland',
	205 => 'Sweden',
	206 => 'Switzerland',
	207 => 'Syria',
	208 => 'Taiwan',
	209 => 'Tajikistan',
	210 => 'Tanzania',
	211 => 'Thailand',
	212 => 'Togo',
	213 => 'Tokelau Country',
	214 => 'Tonga',
	215 => 'Trinidad And Tobago',
	216 => 'Tunisia',
	217 => 'Turkey',
	218 => 'Turkmenistan',
	219 => 'Turks And Caicos Islands',
	220 => 'Tuvalu Country',
	221 => 'Uganda',
	222 => 'Ukraine',
	223 => 'United Arab Emirates',
	224 => 'United Kingdom',
	225 => 'United States',
	226 => 'United States Minor Outlying Islands',
	227 => 'Uruguay',
	228 => 'Uzbekistan',
	229 => 'Vanuatu',
	230 => 'Vatican City State',
	231 => 'Venezuela',
	232 => 'Vietnam',
	233 => 'British Virgin Islands',
	234 => 'United States Virgin Islands',
	235 => 'Wallis And Futuna Islands',
	236 => 'Western Sahara',
	237 => 'Yemen',
	238 => 'Canary Islands',
	239 => 'Zambia',
	240 => 'Zimbabwe',
	241 => 'Clipperton Island',
	242 => 'Montenegro',
	243 => 'Serbia',
	244 => 'Saint Barthelemy',
	245 => 'Saint Martin',
	246 => 'Latin America',
	247 => 'Ascension Island',
	248 => 'Aland Islands',
	249 => 'Diego Garcia',
	250 => 'Ceuta And Melilla',
	251 => 'Isle Of Man',
	252 => 'Jersey',
	253 => 'Tristan Da Cunha',
	254 => 'South Sudan',
	255 => 'Bonaire',
	256 => 'Sint Maarten',
	257 => 'Kosovo',
	258 => 'European Union',
	259 => 'Outlying Oceania',
	260 => 'World',
	261 => 'Europe'
);

$instruments = array(
	0 => '-',
	1 => 'Drums',
	2 => 'Djembe',
	3 => 'Electric Guitar',
	4 => 'Acoustic Guitar',
	5 => 'Bass Guitar',
	6 => 'Keyboard',
	7 => 'Synthesizer',
	8 => 'Grand Piano',
	9 => 'Accordion',
	10 => 'Vocal',
	11 => 'Microphone',
	12 => 'Harmonica',
	13 => 'Trumpet',
	14 => 'Trombone',
	15 => 'French Horn',
	16 => 'Tuba',
	17 => 'Saxophone',
	18 => 'Clarinet',
	19 => 'Flute',
	20 => 'Violin',
	21 => 'Cello',
	22 => 'Double Bass',
	23 => 'Recorder',
	24 => 'Streamer',
	25 => 'Listener',
	26 => 'Guitar Vocal',
	27 => 'Keyboard Vocal',
	28 => 'Bodhran',
	29 => 'Bassoon',
	30 => 'Oboe',
	31 => 'Harp',
	32 => 'Viola',
	33 => 'Congas',
	34 => 'Bongo',
	35 => 'Vocal Bass',
	36 => 'Vocal Tenor',
	37 => 'Vocal Alto',
	38 => 'Vocal Soprano',
	39 => 'Banjo',
	40 => 'Mandolin',
	41 => 'Ukulele',
	42 => 'Bass Ukulele',
	43 => 'Vocal Baritone',
	44 => 'Vocal Lead',
	45 => 'Mountain Dulcimer',
	46 => 'Scratching',
	47 => 'Rapping',
	48 => 'Vibraphone',
	49 => 'Conductor'
);

$skills = array(
	0 => '-',
	1 => 'Beginner',
	2 => 'Intermediate',
	3 => 'Expert'
);

$opsys = array(
	0 => 'Windows',
	1 => 'MacOS',
	2 => 'Linux',
	3 => 'Android',
	4 => 'iOS',
	5 => 'Unix'
);

class CRC {
	var $sr;
	var $bmask = 0x10000;	// 1 << 16
	var $poly = 0x1020;	// (1 << 5) | (1 << 12)

	function __construct($s = null) {
		$this->Reset();
		if (isset($s)) {
			$this->AddString($s);
		}
	}

	function Reset() {
		$this->sr = ~0;
	}

	function AddByte($b) {
		for ($i = 0; $i < 8; $i++) {
			$this->sr <<= 1;
			if ($this->sr & $this->bmask) $this->sr |= 1;

			if ($b & (1 << (7-$i))) $this->sr ^= 1;

			if ($this->sr & 1) $this->sr ^= $this->poly;
		}
	}

	function AddString($s) {
		for ($i=0, $j=strlen($s); $i < $j; $i++) {
			$this->AddByte(ord($s[$i]));
		}
	}

	function Get() {
		return (~$this->sr & ($this->bmask - 1));
	}
}

//-----------------------------------------------------------------------------
// send a silent audio frame
//-----------------------------------------------------------------------------
function send_audio($sock, $size, $ip, $port) {
	$data = pack("CCCa".($size-3), 0, 255, 254, '');

	//print chunk_split(bin2hex($data),2,' ')."\n";

	$n = socket_sendto($sock, $data, strlen($data), 0, $ip, $port);

	if ($n === false) {
		die("Send error: ".socket_strerror(socket_last_error()));
	}
}


//-----------------------------------------------------------------------------
// send an ackn message
//-----------------------------------------------------------------------------
function send_ackn($sock, $cnt, $id, $ip, $port) {
	$data = pack('vvCvv', 0, ACKN, $cnt, 2, $id);

	// need to calculate CRC
	$crc = new CRC($data);
	$data .= pack('v', $crc->Get());
	unset($crc);

	// print chunk_split(bin2hex($data),2,' ')."\n";

	$n = socket_sendto($sock, $data, strlen($data), 0, $ip, $port);

	if ($n === false) {
		die("Send error: ".socket_strerror(socket_last_error()));
	}
}

//-----------------------------------------------------------------------------
// send a request message
//-----------------------------------------------------------------------------
function send_request($sock, $id, $ip, $port) {
	$data = pack('vvCv', 0, $id, 0, 0);

	// need to calculate CRC
	$crc = new CRC($data);
	$data .= pack('v', $crc->Get());
	unset($crc);

	// print chunk_split(bin2hex($data),2,' ')."\n";

	$n = socket_sendto($sock, $data, strlen($data), 0, $ip, $port);

	if ($n === false) {
		die("Send error: ".socket_strerror(socket_last_error()));
	}
}

//-----------------------------------------------------------------------------
// send a request message
//-----------------------------------------------------------------------------
function send_ping_with_num_clients($sock, $ip, $port) {
	$id = CLM_PING_MS_WITHNUMCLIENTS;

	$timems = intval(gettimeofday(TRUE) * 1000) % 86400000;

	$data = pack('vvCvVC', 0, $id, 0, 5, $timems, 0);

	// need to calculate CRC
	$crc = new CRC($data);
	$data .= pack('v', $crc->Get());
	unset($crc);

	// print chunk_split(bin2hex($data),2,' ')."\n";

	$n = socket_sendto($sock, $data, strlen($data), 0, $ip, $port);

	if ($n === false) {
		die("Send error: ".socket_strerror(socket_last_error()));
	}
}

//-----------------------------------------------------------------------------
// process a received datagram
//-----------------------------------------------------------------------------
function process_received($sock, $data, $n, $fromip, $fromport) {
	global $numip, $ip, $port;
	global $servers, $serverbyip;
	global $clientcount;
	global $countries, $instruments, $skills, $opsys;
	global $listcomplete, $msgqueue, $done;

	// print chunk_split(bin2hex($data),2,' ')."\n";

	$crc = new CRC(substr($data, 0, -2));
	$calccrc = $crc->Get();
	$recvcrc = unpack("vcrc", substr($data, -2, 2))['crc'];
	//printf("CRC calc=%04X recv=%04X (%s)\n", $calccrc, $recvcrc, $calccrc==$recvcrc ? 'GOOD' : 'BAD');
	unset($crc);

	if ($recvcrc != $calccrc) {
		die("CRC mismatch in received message");
	}

	$r = unpack("vtag/vid/Ccnt/vlen", substr($data, 0, 7));
	// print_r($r);

	if ($r['len']+9 != $n) {
		die("Malformed packet - length mismatch");
	}

	// print("ID=".$r['id']."\n");

	if (!$listcomplete && $r['id'] != CLM_SERVER_LIST) {
		$msgqueue[] = array($data, $n, $fromip, $fromport);
		// print("Packet queued n=$n, fromip=$fromip, $fromport=$fromport\n");
		return;
	}

	// acknowledge message if it needs it
	if ($r['id'] < CLM_START && $r['id'] > ACKN) {
		send_ackn($sock, $r['cnt'], $r['id'], $fromip, $fromport);
	}

	switch($r['id']) {
	case REQ_SPLIT_MESS_SUPPORT:
	case REQ_NETW_TRANSPORT_PROPS:
	case REQ_JITT_BUF_SIZE:
	case REQ_CHANNEL_INFOS:
		break;

	case CHAT_TEXT:
		if (isset($serverbyip[$fromip][$fromport])) {
			$index = $serverbyip[$fromip][$fromport];
			$server =& $servers[$index];
			$resp = unpack("vlen", substr($data, 7, 2)); $i = 9;
			$len = $resp['len'];
			$a = unpack("a{$len}welcome", substr($data, $i, $len)); $i += $len;
			$server['welcome'] = $a['welcome'];
			unset($server);
		} else {
			error_log("Unexpected CHAT_TEXT from $fromip:$fromport");
		}
		$done = true;	// received the welcome message

		break;

	case VERSION_AND_OS:
		if (isset($serverbyip[$fromip][$fromport])) {
			$index = $serverbyip[$fromip][$fromport];
			$server =& $servers[$index];
			$resp = unpack("Cos/vlen", substr($data, 7, 3)); $i = 10;
			$len = $resp['len'];
			$a = unpack("a{$len}version", substr($data, $i, $len)); $i += $len;
			$server['os'] = array_key_exists($resp['os'], $opsys) ? $opsys[$resp['os']] : 'Unknown';
			$server['version'] = $a['version'];
			if (preg_match('/(\d+)\.(\d+)\.(\d+)(.*)/',$a['version'],$m)) {
				if ($m[4] == '') {
					$k = '=';
				} elseif (strncmp($m[4], 'rc', 2)==0 || strncmp($m[4], 'beta', 4)==0 || strncmp($m[4], 'alpha', 5)==0) {
					$k = '<';
				} else {
					$k = '>';
				}
				$server['versionsort'] = sprintf("%03d%03d%03d%s%s", $m[1], $m[2], $m[3], $k, $m[4]);
			}
			unset($server);
		} else {
			error_log("Unexpected VERSION_AND_OS from $fromip:$fromport\n");
		}
		$done = true;	// always comes after welcome message

		break;

	case RECORDER_STATE:
		if (isset($serverbyip[$fromip][$fromport])) {
			$index = $serverbyip[$fromip][$fromport];
			$server =& $servers[$index];
			$resp = unpack("Crecstate", substr($data, 7, 1));
			$server['recstate'] = $resp['recstate'];
			unset($server);
		} else {
			error_log("Unexpected RECORDER_STATE from $fromip:$fromport");
		}
		$done = true;	// always comes after welcome message

		break;

	case CLIENT_ID:
		if (isset($serverbyip[$fromip][$fromport])) {
			$index = $serverbyip[$fromip][$fromport];
			$server =& $servers[$index];
			$resp = unpack("Cclient_id", substr($data, 7, 1));
			$server['client_id'] = $resp['client_id'];
			unset($server);
		} else {
			error_log("Unexpected CLIENT_ID from $fromip:$fromport");
		}

		break;

	case CLM_CHANNEL_LEVEL_LIST:
		break;

	case CLM_SERVER_LIST:

		for ($i = 7; $i < $n-2;) {
			$server = unpack("Vnumip/vport/vcountry/Cmaxclients/Cperm/vlen", substr($data, $i, 12)); $i += 12;
			$server['country'] = array_key_exists($server['country'], $countries) ? $countries[$server['country']] : 'Unknown';
			$len = $server['len']; unset($server['len']);
			$a = unpack("a{$len}name/vlen", substr($data, $i, $len+2)); $i += $len+2;
			$server['name'] = $a['name'];
			$len = $a['len'];
			$a = unpack("a{$len}ipaddrs/vlen", substr($data, $i, $len+2)); $i += $len+2;
			$server['ipaddrs'] = $a['ipaddrs'];
			$len = $a['len'];
			$a = unpack("a{$len}city", substr($data, $i, $len+2)); $i += $len;
			$server['city'] = $a['city'];

			if ($server['numip'] == 0 && $server['port'] == 0) {
				$server['ip'] = $ip;
				$server['numip'] = $numip;
				$server['port'] = $port;
			} else {
				$server['ip'] = long2ip($server['numip']);
			}
			$server['ping'] = -1;
			$server['os'] = '';
			$server['version'] = '';
			$server['versionsort'] = '';
			$servers[] = $server;
		}

		$index = 0;
		foreach ($servers as $index => $server) {
			$serverbyip[$server['ip']][$server['port']] = $index;
			send_ping_with_num_clients($sock, $server['ip'], $server['port']);
		}

		// print_r($servers);

		$listcomplete = true;
		foreach ($msgqueue as $msg) {
			process_received($sock, $msg[0], $msg[1], $msg[2], $msg[3]);
		}
		$msgqueue = array();

		break;
	case CLM_EMPTY_MESSAGE:
		if (isset($serverbyip[$fromip][$fromport])) {
			$index = $serverbyip[$fromip][$fromport];
			$server =& $servers[$index];
			if ($server['ping'] < 0) {
				// still waiting for ping reply - try again
				send_ping_with_num_clients($sock, $fromip, $fromport);
			}
			unset($server);
		} elseif (isset($serverbyip[$fromip])) {
			// must be the same host - set the first one that isn't already set
			foreach ($serverbyip[$fromip] as $port => $index) {
				$server =& $servers[$index];
				if (!isset($server['port2'])) {
					$server['port2'] = $fromport;
					//$server['port'] = $fromport;
					$serverbyip[$fromip][$fromport] = $index;
					//unset($serverbyip[$fromip][$port]);
					send_ping_with_num_clients($sock, $fromip, $fromport);
					break;
				}
				unset($server);
			}
		} else {
			error_log("Unexpected CLM_EMPTY_MESSAGE from $fromip:$fromport");
		}
		break;
	case CLM_PING_MS_WITHNUMCLIENTS:
		if (isset($serverbyip[$fromip][$fromport])) {
			$index = $serverbyip[$fromip][$fromport];
			$server =& $servers[$index];
			$resp = unpack("Vtimems/Cnclients", substr($data, 7, 5));
			if ($server['ping'] < 0) {
				// discard first ping and request again
				$server['ping'] = 0;
				$server['nclients'] = $resp['nclients'];
				send_ping_with_num_clients($sock, $fromip, $fromport);
			} else {
				$timems = intval(gettimeofday(TRUE) * 1000) % 86400000;
				$server['ping'] = $timems - $resp['timems'];
				send_request($sock, CLM_REQ_VERSION_AND_OS, $fromip, $fromport);
				if ($server['nclients'] = $resp['nclients']) {
					send_request($sock, CLM_REQ_CONN_CLIENTS_LIST, $fromip, $fromport);
				}
			}
			unset($server);
		} else {
			error_log("Unexpected CLM_PING_MS_WITHNUMCLIENTS from $fromip:$fromport");
		}

		break;
	case CLM_CONN_CLIENTS_LIST:
		if (isset($serverbyip[$fromip][$fromport])) {
			$index = $serverbyip[$fromip][$fromport];
			$server =& $servers[$index];
			$clients = array();

			for ($i = 7; $i < $n-2;) {
				$client = unpack("Cchanid/vcountry/Vinstrument/Cskill/Vip/vlen", substr($data, $i, 14)); $i += 14;
				$client['country'] = array_key_exists($client['country'], $countries) ? $countries[$client['country']] : 'Unknown';
				$client['instrument'] = array_key_exists($client['instrument'], $instruments) ? $instruments[$client['instrument']] : 'Unknown';
				$client['skill'] = array_key_exists($client['skill'], $skills) ? $skills[$client['skill']] : 'Unknown';
				$len = $client['len']; unset($client['len']);
				$a = unpack("a{$len}name/vlen", substr($data, $i, $len+2)); $i += $len+2;
				$client['name'] = $a['name'];
				$len = $a['len'];
				$a = unpack("a{$len}city", substr($data, $i, $len)); $i += $len;
				$client['city'] = $a['city'];
				unset($client['ip']);	// no longer sent by server from 3.5.6 onwards
				$clients[] = $client;
			}
			$server['clients'] = $clients;
			$clientcount += count($clients);
			if (isset($_GET['server']) && $server['version'] != '') {
				$done = true;
			}
			unset($server);
		} else {
			error_log("Unexpected CLM_CONN_CLIENTS_LIST from $fromip:$fromport");
		}
		break;
	case CLM_VERSION_AND_OS:
		if (isset($serverbyip[$fromip][$fromport])) {
			$index = $serverbyip[$fromip][$fromport];
			$server =& $servers[$index];
			$resp = unpack("Cos/vlen", substr($data, 7, 3)); $i = 10;
			$len = $resp['len'];
			$a = unpack("a{$len}version", substr($data, $i, $len)); $i += $len;
			$server['os'] = array_key_exists($resp['os'], $opsys) ? $opsys[$resp['os']] : 'Unknown';
			$server['version'] = $a['version'];
			if (preg_match('/(\d+)\.(\d+)\.(\d+)(.*)/',$a['version'],$m)) {
				if ($m[4] == '') {
					$k = '=';
				} elseif (strncmp($m[4], 'rc', 2)==0 || strncmp($m[4], 'beta', 4)==0 || strncmp($m[4], 'alpha', 5)==0) {
					$k = '<';
				} else {
					$k = '>';
				}
				$server['versionsort'] = sprintf("%03d%03d%03d%s%s", $m[1], $m[2], $m[3], $k, $m[4]);
			}
			if (isset($_GET['server']) && ($server['nclients'] == 0 || isset($server['clients']))) {
				$done = true;
			}
			unset($server);
		} else {
			error_log("Unexpected CLM_VERSION_AND_OS from $fromip:$fromport\n");
		}
		break;
	}
}
//-----------------------------------------------------------------------------

$sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

for ($clientport = CLIENT_PORT; $clientport < CLIENT_PORT + CLIENT_PORTS_TO_TRY; $clientport++) {
	if (@socket_bind($sock, '0.0.0.0', $clientport)) {
		break;
	}
}

socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, array('sec'=>1, 'usec'=>500000));

if (isset($_GET['query'])) {
	$servers = array(array('index' => 0, 'name' => $host, 'numip' => $numip, 'ip' => $ip, 'port' => $port, 'ping' => -1, 'os' => '', 'version' => '', 'versionsort' => ''));
	$serverbyip[$ip][$port] = 0;
	$listcomplete = true;
	send_audio($sock, 25, $ip, $port);
} elseif (isset($_GET['directory'])) {
	send_request($sock, CLM_REQ_SERVER_LIST, $ip, $port);
} else {
	$servers = array(array('index' => 0, 'name' => $host, 'numip' => $numip, 'ip' => $ip, 'port' => $port, 'ping' => -1, 'os' => '', 'version' => '', 'versionsort' => ''));
	$serverbyip[$ip][$port] = 0;
	$listcomplete = true;
	send_ping_with_num_clients($sock, $ip, $port);
}

// set defaults in case we don't receive any packets
$fromip = $ip;
$fromport = $port;

// $maxgap = 0;
// $last = gettimeofday(true);

while (!$done && $n = socket_recvfrom($sock, $data, 32767, 0, $fromip, $fromport)) {
	// printf("socket_recvfrom: %d bytes received from %s:%d\n", $n, $fromip, $fromport);

	if ($n != strlen($data)) {
		die("Returned data length does not match string");
	}

	// $now = gettimeofday(true);

	// if ($now - $last > $maxgap) $maxgap = $now - $last;
	// $last = $now;

	process_received($sock, $data, $n, $fromip, $fromport);
}

if (isset($_GET['query'])) {
	// disconnect from server
	send_request($sock, CLM_DISCONNECTION, $fromip, $fromport);
	send_request($sock, CLM_DISCONNECTION, $fromip, $fromport);
}

// print_r($servers);

// printf("%d servers total\n", count($servers));
// printf("%d clients total\n", $clientcount);

socket_close($sock);

// error_log(sprintf("Max gap between responses = %d ms (%s) for %s", $maxgap * 1000, $_GET['directory'], $_SERVER['REMOTE_ADDR']));

for ($i = 0, $size = count($servers); $i < $size; $i++) {
	if ($servers[$i]['ping'] < 0 && isset($_GET['server'])) {
		// no reply from a single server - delete it
		unset($servers[$i]);
	} else {
		$servers[$i]['index'] = $i;
	}
}

$pretty = isset($_GET['pretty']) ? JSON_PRETTY_PRINT : 0;

print json_encode($servers, $pretty | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
print "\n";

// cache the contents
if ($tmp) {
	fwrite($tmp, ob_get_contents());
	fclose($tmp);
	// now move the new data into place atomically
	rename($tmpfile, $cachefile);
	unset($tmpfile);	// no need to cleanup now
}
ob_end_flush();
?>
