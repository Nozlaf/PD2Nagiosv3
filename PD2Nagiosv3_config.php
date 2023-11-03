<?PHP
/*******************************************************************************************************
 *
 *    Nagios PagerDuty Nagios Integration v3 NextGen on Prem 4.0
 *    Originally Written by Sean Falzon
 *    Copyright Sean Falzon 
 *    No warranty is provided for this code use at own risk
 *    version 0.1.1
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
$config->apiendpoint = 'api.pagerduty.com';

// Multiple webhook secret key's can be defined to allow for multiple webhook definitions or rolling upgrades from PagerDuty
$config->webhooksecrets = array(
    "key1" => "webhooksecret1",
    "key2" => "webhooksecret2"
);
//What Method? "CGI, "FILE" and "NRDP are intended to be supported however only NRDP at this stage
$config->method = "NRDP";

//Options for NRDP
$config->nrdpurl = "http://nagioshost.internal/nrdp";  // NRDP is required for this implementation currently
$config->nrdpsecret = "Secret123!";
//Options for FILE
$config->extcmdfile = "/tmp/cmdfile.txt"; 
$config->debug = true; // Turn on for Log Output
