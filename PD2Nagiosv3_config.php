<?PHP
/*******************************************************************************************************
 *
 *    Nagios PagerDuty Nagios Integration v3
 *    Originally Written by Sean Falzon
 *    Copyright Sean Falzon 
 *    No warranty is provided for this code use at own risk
 *    version 1.0.1
 *    Current limitations - untested
 *    Requires NRDP
 * 
 * 
 * 
 *    tested with PHP 8.0.8 on Ubuntu 21.10 nginx 1.23.0-1~impish
 *    connecting to remote nagios core 4.4.5 server via nrdp 2.0.5
 * 
 *
 * 
 * ****************************************************************************************************/

namespace NagiosPDBridge;

if (!defined("NAGIOSPDBRIDGE")) die();

$config->apiKey = 'u+xxxxxxxxxx';  // Api Key for the PagerDuty instance, this can be readonly key
$config->apiendpoint = 'api.pagerduty.com'; // if you are using EU zone PagerDuty set this to the EU API endpoint
$config->securemode = 'false'; // Set to true if you want to  make sure that the source IP is from PagerDuty
// Multiple webhook secret key's can be defined to allow for multiple webhook definitions or rolling upgrades from PagerDuty
$config->webhooksecrets = array(
    "key1" => "webhooksecret1",
    "key2" => "webhooksecret2"
);
//What Method? "CGI, "FILE" and "NRDP are intended to be supported however NRDP is fully tested and FILE is partially tested, feedback is welcome
$config->method = "NRDP";

//Options for NRDP
$config->nrdpurl = "http://nagioshost.internal/nrdp";  // NRDP server address needs to be accessible from the host running this script
// HTTPS support is untesetd but should work atleast if valid certificate is provided
$config->nrdpsecret = "Secret123!";
//Options for FILE
$config->extcmdfile = "/tmp/cmdfile.txt";
$config->debug = true; // Turn on for Log Output
