<?php
// S Ashby, 30/10/23, created
// script to poll PSA Connected Car API and push data into Domoticz via MQTT broker
// 23/11/23, add MQTT API support to be able to wakeup car else we get no data updates overnight
// 24/11/23, fix error in status API - remove extension=odometer, returns 400 server error if used.

// UPDATE VALUES IN <BRACES> WITH DATA SCRAPED FROM A WORKING API TOOL! 
// UPDATE REFRESH TOKENS IN FILES: rtok.txt, vrtok.txt SIMILARLY. HTTP API TOKEN GOES IN rtok.txt, VIRTUALKEY MQTT TOKEN GOES IN vrtok.txt

// debug
$debug=false;

echo "PSA-Connected Car->Domo MQTT bridge started\n";

require "../phpMQTT-master/phpMQTT.php";

$domo_idx_soc = 9999; // <INSERT domoticz IDX for the battery SoC> here

$server = "localhost";     // change if necessary
$port = 1883;                     // change if necessary
$client_id = "psacc-subscriber"; // make sure this is unique for connecting to sever - you could use uniqid()
$username = ''; // MQTT credentials if required
$password = '';
// PSACC API config / credentials
// TOKEN URL is used with the Basic auth header, which is simply base64encode(client_id+':'+client_secret) which should be static, and a refresh token which is seeded from the psa-connect app :)
// refresh token is set/updated in the rtok.txt file
// STATUS URL also includes the vehicle ID which should be static and can also be obtained from the psa-connect app :)
define(TOKEN_URL,'https://idpcvs.vauxhall.co.uk/am/oauth2/access_token');
define(STATUS_URL,'https://api.groupe-psa.com/connectedcar/v4/user/vehicles/<INSERT VEHICLE ID HERE>/status?client_id=122f3511-4f74-4a0c-bcda-af2f3b2e3a65');
define(BASIC_AUTH,'Authorization: Basic MTIyZjM1MTEtNGY3NC00YTBjLWJjZGEtYWYyZjNiMmUzYTY1Ok4xaVkzak80akkxc0YyeVM2eUozckc3eFE0a0w0a0sxZE8zeFQ1dVg2ZEYza1c4Z0k2');
define(GRANT_STR,'grant_type=refresh_token&scope=openid+profile&refresh_token=');
define(RTOK_FILE,'rtok.txt');
define(BRAND_REALM,"x-introspect-realm: clientsB2CVauxhall"); // static, set as per car type - refer Stellantis API docs for other values
define(PSA_COOKIE, 'Cookie: PSACountry=GB'); // Dunno, it's there. Put it in...

// MQTT API requires another access token for the virtualKey service
define(VTOKEN_URL, 'https://api.groupe-psa.com/connectedcar/v4/virtualkey/remoteaccess/token?client_id=122f3511-4f74-4a0c-bcda-af2f3b2e3a65');
define(VGRANT_JSON, '{"grant_type": "refresh_token", "refresh_token": "unset"}');
define(VRTOK_FILE,'vrtok.txt');
define(PSA_MQTT_HOST,'mwa.mpsa.com');
define(PSA_MQTT_PORT,8885);
define(PSA_MQTT_USER,'IMA_OAUTH_ACCESS_TOKEN');
define(PSA_WAKEUP_TOPIC,'psa/RemoteServices/from/cid/<INSERT CUSTOMER ID>/VehCharge/state');
define(PSA_WAKEUP_JSON,'{"access_token": "vatoken", "customer_id": "<INSERT CUSTOMER ID>", "correlation_id": "<INSERT ANY 32 HEX CHAR GUID HERE>", "req_date": "YYYY-MM-DDTHH:mm:ssZ", "vin": "<INSERT VIN HERE>", "req_parameters": {"action": "state"}}');
define(PSA_MQTT_TIMEOUT,900); // reconnect after this time, token will have expired

// polling interval (sec)
$polling_sec = 3600;

// telemetry interval
$telemetry_sec = 7200;

// wakeup predelay - should be long enough to get data back so status is up to date. 1min is not enough!
$wakeup_pre = 120;

// include Syslog class for remote syslog feature
require "../Syslog-master/Syslog.php";
Syslog::$hostname = "localhost";
Syslog::$facility = LOG_DAEMON;
Syslog::$hostToLog = "psacc-domo";

function report($msg, $level = LOG_INFO, $cmp = "psacc-domo") {
	global $debug;
	if(!$debug && $level === LOG_DEBUG) return; // skip debug level msg if disabled
	if($debug) echo "Report:".$msg."\tLevel:".$level."\n";
    Syslog::send($msg, $level, $cmp);
}

// PSACC API functions
$curl = false;
$headers = false;
$atoken = false;
$rtoken = false;
$psa_connect_at = false;

// connect/login to PSA service and cache auth + refresh tokens
function connect_psa() {
	global $curl;
	global $headers;
	global $atoken;
	global $rtoken;
	global $psa_connect_at;

	// set headers for token API
	$headers = array(
	   "Accept: application/json",
	   "Content-Type: application/x-www-form-urlencoded",
	   BASIC_AUTH
	);
	$atoken = false;
	$rtoken = false;
	// make curl object if not done
	if(!$curl) {
		report('opening cURL instance',LOG_DEBUG);
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10); // should be plenty!
		curl_setopt($curl, CURLOPT_TIMEOUT, 10);
		//for debug only!
		curl_setopt($curl, CURLOPT_VERBOSE, true);
		// curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
		// curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
	}
	
	// get rtoken from file
	$f = fopen(RTOK_FILE, 'r') or die('Cannot open refresh token file!');
	$rtoken = fgets($f);
	fclose($f);
	report('using old rtoken:'.$rtoken,LOG_DEBUG);
	
	
	// POST login credentials and cache tokens
	$url = TOKEN_URL;
	$msg = GRANT_STR.$rtoken;
	curl_setopt($curl, CURLOPT_URL, $url);
	curl_setopt($curl, CURLOPT_POSTFIELDS, $msg);

	report('call login to PSACC API. url:'.$url.' post:'.$msg,LOG_DEBUG);
	$resp = curl_exec($curl);
	report('login got:'.$resp,LOG_DEBUG);
	
	// check response
	$code = 0;
	if (false === $resp || ($code = curl_getinfo($curl, CURLINFO_HTTP_CODE)) > 200) {
		report('PSACC login failed. Code:'.$code,LOG_WARNING);
		curl_close($curl); $curl=false;
		return false;
	}
	// save access token
	$data = JSON_decode($resp, false);
	if(!isset($data->access_token)) {
		report('PSACC login token not found. Response: '.$resp,LOG_WARNING);
		curl_close($curl); $curl=false;
		return false;
	}
	$atoken = $data->access_token;
	
	// store refresh token for next time
	if(!isset($data->refresh_token)) {
		report('PSACC refresh token not found. Response: '.$resp,LOG_WARNING);
		curl_close($curl); $curl=false;
		return false;
	}
	$rtoken = $data->refresh_token;

	// set headers for PSACC API
	$headers = array(
	   "Accept: application/hal+json",
	   "Content-Type: application/json",
	   "Authorization: Bearer ".$atoken,
	   BRAND_REALM,
	   PSA_COOKIE
	);
	
	curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
	
	// store rtoken for restarts
	$f = fopen(RTOK_FILE, 'w') or die('Cannot open refresh token file!');
	fwrite($f,$rtoken);
	fclose($f);
	report('saving new rtoken: '.$rtoken,LOG_DEBUG);
		
	$psa_connect_at = time();
	return true;
}

// PSACC MQTT functions
$vatoken = false;
$vrtoken = false;
$psa_mqtt = false;
$psa_mqtt_connect_at = false;

function connect_psa_mqtt() {
	global $debug;
	global $curl;
	global $headers;
	global $atoken;
	global $vatoken;
	global $vrtoken;
	global $psa_mqtt;
	global $psa_mqtt_connect_at;

	// if no curl or atoken, error
	if(!$curl || !$atoken) { report('PSACC MQTT cannot refresh, no active login',LOG_WARNING); return false; }
	
	// clear old values
	if($psa_mqtt)
		$psa_mqtt->close();
	$psa_mqtt = false;
	$vatoken = false;
	$vrtoken = false;
	
	// get vrtoken from file
	$f = fopen(VRTOK_FILE, 'r') or die('Cannot open vKey refresh token file!');
	$vrtoken = fgets($f);
	fclose($f);
	report('using old vrtoken:'.$vrtoken,LOG_DEBUG);

	// POST virtualKey credentials and cache tokens
	$url = VTOKEN_URL;
	$data = JSON_decode(VGRANT_JSON);
	$data->refresh_token = $vrtoken;
	$msg = JSON_encode($data);
	curl_setopt($curl, CURLOPT_URL, $url);
	curl_setopt($curl, CURLOPT_POSTFIELDS, $msg);

	report('call vKey refresh to PSACC API. url:'.$url.' post:'.$msg,LOG_DEBUG);
	$resp = curl_exec($curl);
	report('vKey refresh got:'.$resp,LOG_DEBUG);
	
	// check response
	$code = 0;
	if (false === $resp || ($code = curl_getinfo($curl, CURLINFO_HTTP_CODE)) > 200) {
		report('PSACC vKey refresh failed. Code:'.$code,LOG_WARNING);
		// dont shut this: curl_close($curl); $curl=false;
		return false;
	}
	// save access token
	$data = JSON_decode($resp, false);
	if(!isset($data->access_token)) {
		report('PSACC login token not found. Response: '.$resp,LOG_WARNING);
		// curl_close($curl); $curl=false;
		return false;
	}
	$vatoken = $data->access_token;
	
	// store refresh token for next time
	if(!isset($data->refresh_token)) {
		report('PSACC refresh token not found. Response: '.$resp,LOG_WARNING);
		// curl_close($curl); $curl=false;
		return false;
	}
	$vrtoken = $data->refresh_token;

	// store vrtoken for restarts
	$f = fopen(VRTOK_FILE, 'w') or die('Cannot open vKey refresh token file!');
	fwrite($f,$vrtoken);
	fclose($f);
	report('saving new vrtoken: '.$vrtoken,LOG_DEBUG);
	
	// connect to PSA MQTT service
	// make a unique ID for client_id, enable SSL/TLS
	$psa_mqtt = new phpMQTT($address=PSA_MQTT_HOST, $port=PSA_MQTT_PORT, $client_id='', $cafile=true);
	$psa_mqtt->debug = $debug;
	// connect with system username/pwd
	if(!$psa_mqtt->connect($clean=true, $will=NULL, $username=PSA_MQTT_USER, $password=$vatoken)) {
		report('Cannot connect to PSA MQTT',LOG_NOTICE);
		$psa_mqtt = false;
		return false;
	}
	
	$psa_mqtt_connect_at = time();	
	return true;
}

function wakeup() {
	global $curl;
	global $psa_mqtt;
	global $psa_mqtt_connect_at;
	global $vatoken;

	// check API connection
	if(!$curl) {
		if(!connect_psa()) {
			return false;
		}
	}

	// check MQTT connection and send wakeup - not a critical failure if it does not work :)
	if(!$psa_mqtt || (time() - $psa_mqtt_connect_at > PSA_MQTT_TIMEOUT)) {
		connect_psa_mqtt();
	}
	
	// if PSA MQTT is up, send a wakeup
	if($psa_mqtt) {
		$date = date_create(); // defaults to now
		$data = JSON_decode(PSA_WAKEUP_JSON);
		$data->access_token = $vatoken;
		$data->correlation_id .= date_format($date, 'YmdHis').'000';
		$data->req_date = substr(date_format($date, 'c'),0,19).'Z';
		$msg = JSON_encode($data);
		report('send wakeup:'.$msg,LOG_DEBUG);
		$psa_mqtt->publish(PSA_WAKEUP_TOPIC,$msg);
		return true;
	}
	return false;
}

function get_soc() {
	global $curl;
	
	// check API connection
	if(!$curl) {
		if(!connect_psa()) {
			return false;
		}
	}
	
	// build GET request
	$url = STATUS_URL;
	curl_setopt($curl, CURLOPT_URL, $url);
	curl_setopt($curl, CURLOPT_POSTFIELDS, null);
	curl_setopt($curl, CURLOPT_POST, false);

	report('call status to PSACC API. url:'.$url,LOG_DEBUG);
	$resp = curl_exec($curl);
	// curl_close($curl); // leave connection open if possible 
	report('status API got:'.$resp,LOG_DEBUG);
	
	// check response
	$code = 0;
	if (false === $resp || ($code = curl_getinfo($curl, CURLINFO_HTTP_CODE)) > 200) {
		report('PSACC STATUS API failed. Code:'.$code,LOG_WARNING);
		curl_close($curl); $curl=false; // force reconnect next time
		return false;
	}
	
	return JSON_decode($resp, false);
}

// *** MAIN STARTS HERE ***

/*
 * SIGHUP handler to generate debug trace!
 */
//pcntl_async_signals(TRUE); //not supported in 5.6
declare(ticks = 1); //ouch ...

pcntl_signal(SIGHUP, function($signal) {
	ob_start();
	debug_print_backtrace();
	$trace = ob_get_contents();
	ob_end_clean();
	$trace = 'Debug trace called: '.str_replace(PHP_EOL,',',$trace);
	echo $trace;
	report($trace,LOG_INFO);
});
 

// make MQTT instance for domo
$mqtt = new phpMQTT($server, $port, $client_id);

$start = time();
$lasttelemetry = $start-$telemetry_sec; // run now
$lastwakeup = $start-$polling_sec; // run now
$lastpoll = $start-$polling_sec+$wakeup_pre; // run in wakeup_pre seconds from now
$nodata = true;
$soc = 0;
$soc_at = 'none';
// infinite loop here
while (true) {
	// connect to MQTT domoticz/in topic
	if(!$mqtt->connect(true, NULL, $username, $password)) {
		report('psacc-domo cannot connect to MQTT - retrying in 10 sec',LOG_ERROR);
		sleep(10);
	} else {
		report('psacc-domo connected to queue:'.$server.':'.$port,LOG_NOTICE);
		
// Subscribe to control topic only - might pick up domoticz/out for messaging PSACC if API supports any control inputs that are useful?
//		$topics['domoticz/out'] = array("qos" => 0, "function" => "procmsg");
		$topics['psacc-domo/cmd'] = array("qos" => 0, "function" => "procmsg");
		$mqtt->subscribe($topics, 0);
		procmsg('psacc-domo/cmd','status', false); // get a status msg into MQTT first!

		while($mqtt->proc()){
			$now=time();
			// send telemetry
			if($lasttelemetry < $now-$telemetry_sec) {
				$tele = 'psacc-domo telemetry: last soc = '.$soc.' last soc_at = '.$soc_at;
				$log_lvl = LOG_INFO;
				if($nodata) { $tele = $tele.' ALARM: no data received in telemetry period'; $log_lvl = LOG_ERROR; }
				$nodata = true; // set alarm for next period
				report($tele,$log_lvl);
				$lasttelemetry = $now;
			}
			// send wakeup
			if($lastwakeup < $now-$polling_sec) {
				report('psacc-domo wakeup',LOG_DEBUG);
				procmsg('psacc-domo/cmd','wakeup', false);
				$lastwakeup = $now;
			}
			// poll car status
			if($lastpoll < $now-$polling_sec) {
				report('psacc-domo poll car status',LOG_DEBUG);
				procmsg('psacc-domo/cmd','getsoc', false);
				$lastpoll = $now;
			}
			// dont hog the CPU! proc() is non-blocking
			sleep(1);
		}

		// proc() returned false - reconnect
		report('psacc-domo lost MQTT connection - retrying',LOG_NOTICE);
		$mqtt->close();
	}
}

function procmsg($topic, $msg, $retain){
	global $debug;
	global $mqtt;
	global $psa_mqtt;
	global $telemetry_sec;
	global $polling_sec;
	global $wakeup_pre;
	global $nodata;
	global $atoken;
	global $rtoken;
	global $psa_connect_at;
	global $vatoken;
	global $vrtoken;
	global $psa_mqtt_connect_at;
	global $lasttelemetry;
	global $lastpoll;
	global $domo_idx_soc;
	global $soc;
	global $soc_at;
	
	$now = time();
	// skip retain flag msgs (LWT usually)
	if($retain)
		return;
	// process by topic
	report('msg from:'.$topic,LOG_DEBUG);
	if ($topic=='psacc-domo/cmd') {
		report("cmd:".$msg,LOG_DEBUG);
		if((empty($msg))|| $msg=='status') {
			$data = new stdClass();
			$data->cmd = "status";
			$data->nodata = $nodata;
			$data->secstonextpoll = $lastpoll+$polling_sec-$now;
			$data->secstonexttelemetry = $lasttelemetry+$telemetry_sec-$now;
			$data->atoken = $atoken;
			$data->rtoken = $rtoken;
			$data->psa_connect_at = date('c',$psa_connect_at);
			$data->vatoken = $vatoken;
			$data->vrtoken = $vrtoken;
			$data->psa_mqtt_connect_at = date('c',$psa_mqtt_connect_at);
			$data->last_soc = $soc;
			$data->soc_at = $soc_at;
			$msg = JSON_encode($data);
			$mqtt->publish('psacc-domo/status',$msg,0);
			report('reply:'.$msg,LOG_DEBUG);
			return;
		}
		if($msg=='reset') {
			// logout and reconnect to Geotogether API
			if($psa_mqtt) {$psa_mqtt->close(); $psa_mqtt=false;}
			if(connect_psa()) $state = 'connected';
			else $state = 'unconnected';
			$data = new stdClass();
			$data->cmd = "reset";
			$data->state = $state;
			$msg = JSON_encode($data);
			$mqtt->publish('psacc-domo/status',$msg,0);
			report('reply:'.$msg,LOG_DEBUG);
			return;
		}
		if($msg=='config') {
			$data = new stdClass();
			$data->cmd = "config";
			$data->debug = $debug;
			$data->polling_sec = $polling_sec;
			$data->telemetry_sec = $telemetry_sec;
			$data->wakeup_pre = $wakeup_pre;
			$data->domo_idx_soc = $domo_idx_soc;
			$msg = JSON_encode($data);
			$mqtt->publish('psacc-domo/status',$msg,0);
			report('reply:'.$msg,LOG_DEBUG);
			return;
		}
		if($msg=='debug') {
			$debug = !$debug; // toggle and report debug state
			$data = new stdClass();
			$data->cmd = "debug";
			$data->debug = $debug;
			$msg = JSON_encode($data);
			$mqtt->publish('psacc-domo/status',$msg,0);
			if($debug) echo 'reply:'.$msg."\n";
			return;
		}
		if($msg=='wakeup') {
			$resp = wakeup();
			$data = new stdClass();
			$data->cmd = "wakeup";
			$data->resp = $resp;
			$msg = JSON_encode($data);
			$mqtt->publish('psacc-domo/status',$msg,0);
			if($debug) echo 'reply:'.$msg."\n";
			return;
		}
		if($msg=='getsoc') {
			if(false === ($livedata = get_soc())) {
				// failed - report to status channel
				$data = new stdClass();
				$data->cmd = "getsoc";
				$data->state = "API failed";
				$msg = JSON_encode($data);
				$mqtt->publish('psacc-domo/status',$msg,0);
				report('reply:'.$msg,LOG_WARNING);
			} else {
				// success - send to domoticz
				// check we got a reading!
				$soc = 0;
				if($livedata->energy!=null) {
					$nodata = false; // clear alarm
					$soc = $livedata->energy[0]->level;
					$soc_at = $livedata->energy[0]->updatedAt; // record the time the CAR was last read, not this API!
				}
				$data = new stdClass();
				// update soc idx
				$data->idx = $domo_idx_soc;
				$data->nvalue = 0;
				$data->svalue = strval($soc);
				$msg = JSON_encode($data);
				$mqtt->publish('domoticz/in',$msg,0);
				report('send to domo:'.$msg,LOG_INFO);
			}
			return;
		}
	}
	else {
		// not expecting other topics - skip out
		report('unexpected message topic:'.$topic,LOG_DEBUG);
	}
	return;
}
