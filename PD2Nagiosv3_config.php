<?php
/**
 * PagerDuty to Nagios Integration v3 Configuration
 * 
 * Configuration file for PD2Nagiosv3 integration
 * 
 * @author Sean Falzon
 * @version 1.2.0
 * @copyright Sean Falzon
 * @license MIT
 */

namespace NagiosPDBridge;

if (!defined("NAGIOSPDBRIDGE")) {
    die('Direct access not allowed');
}

// PagerDuty API Configuration
$config->apiKey = 'u+xxxxxxxxxx';  // API Key for the PagerDuty instance (can be readonly key)
$config->apiendpoint = 'api.pagerduty.com'; // EU zone: use 'api.eu.pagerduty.com'

// Security Configuration
$config->securemode = false; // Enable IP filtering from PagerDuty (soft firewall)
$config->webhookValidate = false; // Enable webhook signature validation

// Webhook Security Configuration
// Multiple webhook secret keys can be defined for multiple webhook definitions or rolling upgrades
$config->webhooksecrets = [
    "key1" => "webhooksecret1",
    "key2" => "webhooksecret2"
];

// Additional allowed IP addresses (useful for PDALTAGENT or internal testing)
$config->webhookAdditionalIPs = [
    // "192.168.0.1",
    // "10.0.0.1"
];

// Integration Method Configuration
// Supported methods: "NRDP" (fully tested), "FILE" (partially tested), "CGI" (planned)
$config->method = "NRDP";

// NRDP Configuration
$config->nrdpurl = "http://nagioshost.internal/nrdp";  // NRDP server address
$config->nrdpsecret = "Secret123!"; // NRDP authentication token

// File-based Command Configuration (for FILE method)
$config->extcmdfile = "/tmp/cmdfile.txt";

// Debug Configuration
$config->debug = true; // Enable debug logging
