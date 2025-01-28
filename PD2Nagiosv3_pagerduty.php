<?php

/*******************************************************************************************************
 *      PagerDuty Nagios Bi-Directional Integration 2022 Edition
 *      Originally Written by Sean Falzon
 *      Special thanks to Luke DePellegrini for reviews
 *      Thanks to PagerDuty & Nagios for building great products
 *      Copyright Sean Falzon
 *      No warranty is provided for this code use at own risk
 *      version 1.0.1
 *  
 *      Tested with:
 *               PHP 8.0.8 on Ubuntu 21.10 with nginx 1.23.0-1~impish
 *               PHP 8.1.2 on Ubuntu 22.04.1 with nginx 1.23.0
 *      connecting to remote nagios core 4.4.5 server via nrdp 2.0.5
 *****************************************************************************************************/

 /*******************************************************************************************
  * Usage Notes:
  * see the README
  *******************************************************************************************/


define('NAGIOSPDBRIDGE', true);

$config = new StdClass();
$params = new StdClass();
require "PD2Nagiosv3_config.php";
if ($config->debug == true) {
    $dl = fopen("PD2Nagiosv3_debug.log", "a+")  or die("Unable to open file!");
    fwrite($dl, "\n==== started ==== \n");
}
/**
 * Send Nrdp command
 * We use the external command format to simplify the compatibility
 * 
 * @param string $command is the actual command in plain text
 * @param object $config  is used to store the configuration and its passed around
 * 
 * @return string response from the NRDP server
 */
function sendNrdp($command,$config)
{
    if ($config->debug == true) {
        $dl = fopen("PD2Nagiosv3_debug.log", "a+")  or die("Unable to open file!");
        fwrite($dl, "\n==== SEND NRDP ==== \n");
    }

    $curl = curl_init();
    curl_setopt_array(
        $curl, array(
        CURLOPT_URL => $command,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        )
    );

    $nrdpresponse = curl_exec($curl);
    if ($config->debug == true) {
        fwrite($dl, "\n==== NRDP response ==== \n");
        fwrite($dl, $nrdpresponse);
    }
    curl_close($curl);
    return $nrdpresponse;
}

/**
 * Write a Nagios External command
 * 
 * @param string $command  : the actual command in plain text
 * @param string $filename : the filename for the nagios external command file
 * 
 * @return void
 */
function writeExternalCommand($command, $filename)
{
    $cmdfile = fopen($filename, "a") or die("Unable to open file!");
    fwrite($cmdfile, $command);
}


/**
 * Send a command to nagios/icinga
 * 
 * @param string $command : the actual command in plain text
 * @param object $config  : the configuration object
 * 
 * @return $response : The response from the API decoded from JSON
 */
function sendCommand($command, $config)
{
    // check what method the configuration calls for 
    // if its nrdp sent it using that function otherwise save it to the file


    if ($config->method == "NRDP") {
        sendNrdp($command, $config);
    }
    if ($config->method == "FILE") {
        $cf = fopen($config->extcmdfile, "a+")  or die("Unable to open extenal command file!");
        fwrite($cf, $command."\n");
    }
    

}



/**
 * Get an API endpoint with from PagerDuty
 * 
 * @param string $endpoint : the actual command in plain text
 * @param object $config   : the filename for the nagios external command file
 * 
 * @return $response_j : The response from the API decoded from JSON
 */
function getapi($endpoint, $config)
{
    if ($config->debug == true) {
        $dl = fopen("PD2Nagiosv3_debug.log", "a+")  or die("Unable to open file!");
        fwrite($dl, "\n==== Payload ==== \n");
    }
    $curl = curl_init();
    curl_setopt_array(
        $curl, array(
        CURLOPT_URL => $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
        'Authorization: Token token=' . $config->apiKey,
        'Accept: application/vnd.pagerduty+json;version=2',
        'Content-Type: application/json'
        ),
        )
    );

    $response = curl_exec($curl);
    if ($config->debug == true) {
        fwrite($dl, $response);
    }
    $response_j = json_decode($response);
    curl_close($curl);

    return $response_j;
}


// Begin the processing of the payload

// Check if the debug flag is set and open the debug log file
if ($config->debug == true) {
    $dl = fopen("PD2Nagiosv3_debug.log", "a+")  or die("Unable to open file!");
    fwrite($dl, json_encode($config));
}

// Proceed with the logic only if the Request Method is POST (no other methods are supported or expected)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get the raw input for processing the signature
    $raw_payload = file_get_contents('php://input');

    // Prepare an empty array for the signature validation
    $sigs = [];

    // Loop through the webhook secrets in the config and add the hashed signature to the array
    foreach ($config->webhooksecrets as $key => $value) {
        if ($config->debug == true) {
                fwrite($dl, "key" . $key . "" . $value . "\n");
        }
        array_push($sigs, "v1=" . hash_hmac('sha256', $raw_payload, $value));
    }
    $sourcepayload = json_decode($raw_payload, false);

    // Get all headers
    $headers = getallheaders();

    // Check that the signature in config matches the PagerDuty signature header
    $pdsig = $headers["X-Pagerduty-Signature"];
    if (in_array($pdsig, $sigs)) {
        if ($sourcepayload->event->event_type == "incident.annotated") {
            $incid = $sourcepayload->event->data->incident->id;
        } else {
            $incid = $sourcepayload->event->data->id;
        }
        if ($config->debug == true) {
            fwrite($dl, $incid);
        }
        
        $incdetails  = getapi("https://". $config->apiendpoint . "/incidents/" . $incid, $config);
        $firstlog =  getapi($incdetails->incident->first_trigger_log_entry->self . "?include[]=channels", $config);
        if (isset($firstlog->log_entry->channel->details->SERVICEDESC)) {
            $service = $firstlog->log_entry->channel->details->SERVICEDESC;
        }
    } else {
        if ($config->debug == true) {
            fwrite($dl, "\n==== invalid_signature ==== \n");
            fwrite($dl, json_encode($sigs));
        }
        die('Not a valid signature');
    }
    $event_details = $firstlog->log_entry->event_details;
    if ($config->debug == true) {
        fwrite($dl, "\n==== gathering_params ==== \n");
    }
    $params->user = urlencode($sourcepayload->event->agent->summary);

    // PagerDuty Incident - Annotated Event
    // Add a comment to the Service or Host in Nagios
    if ($sourcepayload->event->event_type == "incident.annotated") {
        $params->comment = urlencode($sourcepayload->event->data->content);
        if ($config->debug == true) {
            fwrite($dl, "\n==== nrdp_command ==== \n");
        }

        if (isset($service)) {
            $params->cmd = "ADD_SVC_COMMENT";
            $nrdpcommand = $config->nrdpurl . '/?token=' . $config->nrdpsecret . '&cmd=submitcmd&command=' . $params->cmd . ';' . $firstlog->log_entry->channel->details->HOSTNAME . ';' . $service  . ';0;' . $params->user . ';' . $params->comment;
            if ($config->debug == true) {
                fwrite($dl, "\n==== " . $nrdpcommand . " ==== \n");
            }
        } else {
            $params->cmd = "ADD_HOST_COMMENT";
            $nrdpcommand = $config->nrdpurl . '/?token=' . $config->nrdpsecret . '&cmd=submitcmd&command=' . $params->cmd . ';' . $firstlog->log_entry->channel->details->HOSTNAME . ';1;' . $params->user . ';' . $params->comment;
            if ($config->debug == true) {
                fwrite($dl, "\n==== " . $nrdpcommand . " ==== \n");
            }
        }
        sendCommand($nrdpcommand, $config);
    }

    // PagerDuty Incident - Acknowledged Event
    // Acknowledge the Service or Host issue in Nagios
    if ($sourcepayload->event->event_type == "incident.acknowledged") {
        $params->comment = urlencode("ACK via PagerDuty");
        if (isset($service)) {
            $params->cmd = "ACKNOWLEDGE_SVC_PROBLEM";
        } else {
            $params->cmd = "ACKNOWLEDGE_HOST_PROBLEM";
        }
        // if it is a service issue which was acknowledged in PD we needs to use a slightly different command to ack it
        if (isset($service)) {
            $nrdpcommand = $config->nrdpurl . '/?token=' . $config->nrdpsecret . '&cmd=submitcmd&command=' . $params->cmd . ';' . $firstlog->log_entry->channel->details->HOSTNAME .';'.$service. ';2;0;0;' . $params->user . ';' . $params->comment;
        } else {
            $nrdpcommand = $config->nrdpurl . '/?token=' . $config->nrdpsecret . '&cmd=submitcmd&command=' . $params->cmd . ';' . $firstlog->log_entry->channel->details->HOSTNAME . ';2;0;0;' . $params->user . ';' . $params->comment;
        }
        fwrite($dl, "\n==== " . $nrdpcommand . " ==== \n");
        sendCommand($nrdpcommand, $config);
    }

    // PagerDuty Incident - Unacknowledged, Escalated, Delegated, or Resolved Event
    // Remove the Service or Host acknowledgement and add a comment saying it has been removed
    $unack = array("incident.unacknowledged", "incident.escalated", "incident.delegated", "incident.resolved");
    if (in_array($sourcepayload->event->event_type, $unack)) {
        $params->comment = urlencode("ACK removed via PagerDuty");
        if (isset($service)) {
            $params->cmd = "REMOVE_SVC_ACKNOWLEDGEMENT";
            $nrdpcommand = $config->nrdpurl . '/?token=' . $config->nrdpsecret . '&cmd=submitcmd&command=' . $params->cmd . ';' . $firstlog->log_entry->channel->details->HOSTNAME . ';' . $service;
            fwrite($dl, "\n==== " . $nrdpcommand . " ==== \n");
        } else {
            $params->cmd = "REMOVE_HOST_ACKNOWLEDGEMENT";
            $nrdpcommand = $config->nrdpurl . '/?token=' . $config->nrdpsecret . '&cmd=submitcmd&command=' . $params->cmd . ';' . $firstlog->log_entry->channel->details->HOSTNAME;
            fwrite($dl, "\n==== " . $nrdpcommand . " ==== \n");
        }
        sendCommand($nrdpcommand, $config);
        fwrite($dl, "\n==== " . $nrdpcommand . " ==== \n");

        $params->comment = urlencode("ACK removed via PagerDuty due to:  " . $sourcepayload->event->event_type);

        $params->cmd = "ADD_HOST_COMMENT";
        $nrdpcommand = $config->nrdpurl . '/?token=' . $config->nrdpsecret . '&cmd=submitcmd&command=' . $params->cmd . ';' . $firstlog->log_entry->channel->details->HOSTNAME . ';false;' . $params->user . ';' . $params->comment;
        fwrite($dl, "\n==== " . $nrdpcommand . " ==== \n");
        sendCommand($nrdpcommand, $config);
    }

    // This will be the new debug log state, the other logs are more for development
    if ($config->debug == true) {
        $fh = fopen("PD2Nagiosv3_debug.log", "a+")  or die("Unable to open file!");
        fwrite($fh, "\n==== signature ==== \n");
        fwrite($fh, json_encode($sigs) . "\n");
        fwrite($fh, "PD SIG" . $pdsig . "\n");
        fwrite($fh, "\n==== channel details ====\n");
        fwrite($fh, serialize($firstlog->log_entry->channel->details) . "\n");
        fwrite($fh, "\n==== event details ====\n");
        fwrite($fh, serialize($event_details) . "\n");
        fwrite($fh, "\n==== source payload details ====\n");
        fwrite($fh, serialize($sourcepayload) . "\n");
        fwrite($fh, "\n==== params details ====\n");
        fwrite($fh, serialize($params) . "\n");
        fwrite($fh, "\n==== nrdp command ====\n");
        fwrite($fh, $nrdpcommand . "\n");
        fwrite($fh, "\n==== config ====\n");
        fwrite($fh, serialize($config) . "\n");
        fwrite($fh, "\n==== sending_nrdp ==== \n");
    }
} else {
    if ($config->debug == true) {
        fwrite($dl, "\n==== invalid_request_method ==== \n");
    }
    die('Not a valid request method');
}
