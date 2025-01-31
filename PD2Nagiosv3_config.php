<?PHP
/*******************************************************************************************************
 *
 *    Nagios PagerDuty Nagios Integration v3
 *    Originally Written by Sean Falzon
 *    Copyright Sean Falzon 
 *    No warranty is provided for this code use at own risk
 *    version 1.1.1
 * 
 *
 * 
 * ****************************************************************************************************/

namespace NagiosPDBridge;

if (!defined("NAGIOSPDBRIDGE")) die();

$config->apiKey = 'u+xxxxxxxxxx';  // Api Key for the PagerDuty instance, this can be readonly key
$config->apiendpoint = 'api.pagerduty.com'; // if you are using EU zone PagerDuty set this to the EU API endpoint
$config->securemode = false; // Set to true if you want to  make sure that the source IP is from PagerDuty like a really soft firewall
$config->webhookValidate = false; // set to true to validate the webhook secret key(s) from PagerDuty
// Multiple webhook secret key's can be defined to allow for multiple webhook definitions or rolling upgrades from PagerDuty
$config->webhooksecrets = array(
    "key1" => "webhooksecret1",
    "key2" => "webhooksecret2"
);
$config->webhookAdditionalIPs = array(
    #"192.168.0.1"
); // Additional IP's that can be used by, this is useful if you are using PDALTAGENT to send webhooks uncomment, add as many as you like, must use single IP's
//What Method? "CGI, "FILE" and "NRDP are intended to be supported however NRDP is fully tested and FILE is partially tested, feedback is welcome
$config->method = "NRDP";

//Options for NRDP
$config->nrdpurl = "http://nagioshost.internal/nrdp";  // NRDP server address needs to be accessible from the host running this script
// HTTPS support is untesetd but should work atleast if valid certificate is provided
$config->nrdpsecret = "Secret123!";
//Options for FILE
$config->extcmdfile = "/tmp/cmdfile.txt";
$config->debug = true; // Turn on for Log Output
