<?php
/**
 * PagerDuty Nagios Bi-Directional Integration 2022 Edition
 * 
 * Handles webhook events from PagerDuty and translates them to Nagios commands
 * 
 * @author Sean Falzon
 * @version 1.2.0
 * @copyright Sean Falzon
 * @license MIT
 * 
 * Tested successfully with:
 * - PHP 8.3.6 on Ubuntu 24.10 with nginx 1.27.3
 * - PHP 8.0.8 on Ubuntu 21.10 with nginx 1.23.0-1~impish
 * - PHP 8.1.2 on Ubuntu 22.04.1 with nginx 1.23.0
 * - Connecting to remote Nagios Core 4.5.8 server via NRDP 2.0.5
 */

// Prevent direct access
if (!defined('NAGIOSPDBRIDGE')) {
    define('NAGIOSPDBRIDGE', true);
}

// Configuration constants
define('CACHE_FILE', 'ip_cache.json');
define('CACHE_TTL', 86400); // Cache time-to-live in seconds (1 day)

// Initialize objects
$config = new stdClass();
$params = new stdClass();

// Load configuration
require_once "PD2Nagiosv3_config.php";

// Initialize debug logging if enabled
if ($config->debug) {
    $debugLog = fopen("PD2Nagiosv3_debug.log", "a+") or die("Unable to open debug log file!");
    fwrite($debugLog, "\n==== Started ==== " . date('Y-m-d H:i:s') . "\n");
}

/**
 * Fetch the list of allowed IPs from PagerDuty
 *
 * @return array List of allowed IPs
 * @throws Exception If unable to fetch IP list
 */
function fetchAllowedIps() {
    $url = 'https://developer.pagerduty.com/ip-safelists/webhooks-us-service-region-json';
    $response = file_get_contents($url);
    
    if ($response === false) {
        throw new Exception("Unable to fetch allowed IPs from PagerDuty");
    }
    
    $data = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON response from PagerDuty IP list");
    }
    
    // Debug: Save response for testing
    if (defined('NAGIOSPDBRIDGE') && isset($GLOBALS['config']) && $GLOBALS['config']->debug) {
        file_put_contents('test_json.json', json_encode($data, JSON_PRETTY_PRINT));
    }
    
    return $data ?? [];
}

/**
 * Get the cached IP list or fetch a new one if the cache is expired
 *
 * @return array List of allowed IPs
 */
function getCachedIps() {
    if (file_exists(CACHE_FILE)) {
        $cacheData = file_get_contents(CACHE_FILE);
        if ($cacheData !== false) {
            $cache = json_decode($cacheData, true);
            if ($cache && isset($cache['timestamp']) && isset($cache['ips'])) {
                if (time() - $cache['timestamp'] < CACHE_TTL) {
                    return $cache['ips'];
                }
            }
        }
    }
    
    try {
        $ips = fetchAllowedIps();
        $cacheData = [
            'timestamp' => time(),
            'ips' => $ips
        ];
        file_put_contents(CACHE_FILE, json_encode($cacheData, JSON_PRETTY_PRINT));
        return $ips;
    } catch (Exception $e) {
        // Log error and return empty array
        if (defined('NAGIOSPDBRIDGE') && isset($GLOBALS['config']) && $GLOBALS['config']->debug) {
            $debugLog = fopen("PD2Nagiosv3_debug.log", "a+");
            fwrite($debugLog, "Error fetching IPs: " . $e->getMessage() . "\n");
        }
        return [];
    }
}

/**
 * Check if the request IP is in the list of allowed IPs
 *
 * @param array $allowedIps List of allowed IPs
 * @return bool True if the IP is allowed, false otherwise
 */
function isIpAllowed($allowedIps) {
    $requestIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return in_array($requestIp, $allowedIps, true);
}

// Fetch the allowed IPs from cache or remote
$allowedIps = getCachedIps();

// IP Security Check
if ($config->securemode) {
    if (php_sapi_name() !== 'cli' && !isIpAllowed($allowedIps)) {
        // Fetch new IP list if the request IP is not in the cached list
        $allowedIps = array_merge(fetchAllowedIps(), $config->webhookAdditionalIPs);
        
        // Check if the request IP is allowed
        if (!isIpAllowed($allowedIps)) {
            // Debug mode: show IP information
            if ($config->debug) {
                echo "Allowed IPs: " . implode(", ", $allowedIps) . "\n";
                echo "Your IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n";
            }
            http_response_code(403);
            die('Request IP is not allowed');
        }
        
        // Update the cache with the new IP list
        $cacheData = [
            'timestamp' => time(),
            'ips' => $allowedIps
        ];
        file_put_contents(CACHE_FILE, json_encode($cacheData, JSON_PRETTY_PRINT));
    }
}

// CLI mode: display allowed IPs and exit
if (php_sapi_name() === 'cli') {
    echo "Allowed IP addresses:\n" . implode("\n", $allowedIps) . "\n";
    exit(0);
}

/**
 * Send NRDP command
 * Uses the external command format to simplify compatibility
 *
 * @param string $command The actual command in plain text
 * @param object $config Configuration object
 * @return string Response from the NRDP server
 */
function sendNrdp($command, $config) {
    if ($config->debug) {
        $debugLog = fopen("PD2Nagiosv3_debug.log", "a+") or die("Unable to open debug log file!");
        fwrite($debugLog, "\n==== SEND NRDP ==== " . date('Y-m-d H:i:s') . "\n");
        fwrite($debugLog, "Command: " . $command . "\n");
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $command,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $nrdpResponse = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    
    if ($config->debug) {
        fwrite($debugLog, "HTTP Code: " . $httpCode . "\n");
        fwrite($debugLog, "NRDP Response: " . $nrdpResponse . "\n");
        fwrite($debugLog, "Curl Error: " . curl_error($curl) . "\n");
    }
    
    curl_close($curl);
    
    if ($nrdpResponse === false) {
        throw new Exception("Failed to send NRDP command");
    }
    
    return $nrdpResponse;
}

/**
 * Validate the PagerDuty signature
 *
 * @param string $rawPayload The raw payload from the request
 * @param array $webhookSecrets The list of webhook secrets from the config
 * @param string $pdSig The PagerDuty signature from the headers
 * @param bool $webhookValidate Whether to validate the webhook
 * @return bool True if the signature is valid, false otherwise
 */
function validateSignature($rawPayload, $webhookSecrets, $pdSig, $webhookValidate) {
    if (!$webhookValidate) {
        return true;
    }
    
    $expectedSignatures = [];
    foreach ($webhookSecrets as $secret) {
        $expectedSignatures[] = "v1=" . hash_hmac('sha256', $rawPayload, $secret);
    }
    
    return in_array($pdSig, $expectedSignatures, true);
}

/**
 * Write a Nagios External command
 *
 * @param string $command The actual command in plain text
 * @param string $filename The filename for the nagios external command file
 * @return void
 * @throws Exception If unable to write to command file
 */
function writeExternalCommand($command, $filename) {
    $cmdFile = fopen($filename, "a");
    if ($cmdFile === false) {
        throw new Exception("Unable to open external command file: " . $filename);
    }
    
    $result = fwrite($cmdFile, $command . "\n");
    fclose($cmdFile);
    
    if ($result === false) {
        throw new Exception("Failed to write to external command file");
    }
}

/**
 * Send a command to nagios/icinga
 *
 * @param string $command The actual command in plain text
 * @param object $config The configuration object
 * @return string The response from the API
 * @throws Exception If command sending fails
 */
function sendCommand($command, $config) {
    try {
        switch ($config->method) {
            case "NRDP":
                return sendNrdp($command, $config);
                
            case "FILE":
                writeExternalCommand($command, $config->extcmdfile);
                return "Command written to file";
                
            default:
                throw new Exception("Unsupported method: " . $config->method);
        }
    } catch (Exception $e) {
        if ($config->debug) {
            $debugLog = fopen("PD2Nagiosv3_debug.log", "a+");
            fwrite($debugLog, "Error sending command: " . $e->getMessage() . "\n");
        }
        throw $e;
    }
}

/**
 * Get an API endpoint from PagerDuty
 *
 * @param string $endpoint The API endpoint URL
 * @param object $config The configuration object
 * @return object The response from the API decoded from JSON
 * @throws Exception If API request fails
 */
function getApi($endpoint, $config) {
    if ($config->debug) {
        $debugLog = fopen("PD2Nagiosv3_debug.log", "a+") or die("Unable to open debug log file!");
        fwrite($debugLog, "\n==== API Request ==== " . date('Y-m-d H:i:s') . "\n");
        fwrite($debugLog, "Endpoint: " . $endpoint . "\n");
    }
    
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => [
            'Authorization: Token token=' . $config->apiKey,
            'Accept: application/vnd.pagerduty+json;version=2',
            'Content-Type: application/json'
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    
    if ($config->debug) {
        fwrite($debugLog, "HTTP Code: " . $httpCode . "\n");
        fwrite($debugLog, "Response: " . $response . "\n");
    }
    
    curl_close($curl);
    
    if ($response === false) {
        throw new Exception("Failed to fetch API endpoint: " . $endpoint);
    }
    
    $responseJson = json_decode($response);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON response from API");
    }
    
    return $responseJson;
}

// Main processing logic
if ($config->debug) {
    $debugLog = fopen("PD2Nagiosv3_debug.log", "a+") or die("Unable to open debug log file!");
    fwrite($debugLog, "Configuration loaded: " . json_encode($config, JSON_PRETTY_PRINT) . "\n");
}

// Only process POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($config->debug) {
        fwrite($debugLog, "\n==== Invalid request method: " . $_SERVER['REQUEST_METHOD'] . " ==== \n");
    }
    http_response_code(405);
    die('Method not allowed. Only POST requests are supported.');
}

// Get the raw input for processing the signature
$rawPayload = file_get_contents('php://input');
if ($rawPayload === false) {
    http_response_code(400);
    die('Unable to read request body');
}

// Get headers and validate PagerDuty signature
$headers = getallheaders();
if (!isset($headers["X-Pagerduty-Signature"])) {
    http_response_code(401);
    die('Missing PagerDuty signature header');
}

$pdSig = $headers["X-Pagerduty-Signature"];

// Validate the signature
if (!validateSignature($rawPayload, $config->webhooksecrets, $pdSig, $config->webhookValidate)) {
    if ($config->debug) {
        fwrite($debugLog, "\n==== Invalid signature ==== \n");
        fwrite($debugLog, "Expected secrets: " . json_encode($config->webhooksecrets) . "\n");
        fwrite($debugLog, "Received signature: " . $pdSig . "\n");
    }
    http_response_code(401);
    die('Invalid signature');
}

// Parse the payload
$sourcePayload = json_decode($rawPayload, false);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    die('Invalid JSON payload');
}

// Handle ping events
if ($sourcePayload->event->event_type === "pagey.ping") {
    die('Pong!');
}

// Get incident ID
if ($sourcePayload->event->event_type === "incident.annotated") {
    $incidentId = $sourcePayload->event->data->incident->id;
} else {
    $incidentId = $sourcePayload->event->data->id;
}

if ($config->debug) {
    fwrite($debugLog, "Processing incident ID: " . $incidentId . "\n");
}

// Get incident details and first log entry
try {
    $incidentDetails = getApi("https://" . $config->apiendpoint . "/incidents/" . $incidentId, $config);
    $firstLog = getApi($incidentDetails->incident->first_trigger_log_entry->self . "?include[]=channels", $config);
    
    // Extract service name if available
    $service = null;
    if (isset($firstLog->log_entry->channel->details->SERVICEDESC)) {
        $service = $firstLog->log_entry->channel->details->SERVICEDESC;
    }
    
    $eventDetails = $firstLog->log_entry->event_details;
    
    if ($config->debug) {
        fwrite($debugLog, "\n==== Gathering parameters ==== \n");
    }
    
    $params->user = urlencode($sourcePayload->event->agent->summary);
    
    // Process different event types
    switch ($sourcePayload->event->event_type) {
        case "incident.annotated":
            // Add a comment to the Service or Host in Nagios
            $params->comment = urlencode($sourcePayload->event->data->content);
            
            if (isset($service)) {
                $params->cmd = "ADD_SVC_COMMENT";
                $nrdpCommand = $config->nrdpurl . '/?token=' . $config->nrdpsecret . 
                              '&cmd=submitcmd&command=' . $params->cmd . ';' . 
                              $firstLog->log_entry->channel->details->HOSTNAME . ';' . 
                              $service . ';0;' . $params->user . ';' . $params->comment;
            } else {
                $params->cmd = "ADD_HOST_COMMENT";
                $nrdpCommand = $config->nrdpurl . '/?token=' . $config->nrdpsecret . 
                              '&cmd=submitcmd&command=' . $params->cmd . ';' . 
                              $firstLog->log_entry->channel->details->HOSTNAME . ';1;' . 
                              $params->user . ';' . $params->comment;
            }
            break;
            
        case "incident.acknowledged":
            // Acknowledge the Service or Host issue in Nagios
            $params->comment = urlencode("ACK via PagerDuty");
            
            if (isset($service)) {
                $params->cmd = "ACKNOWLEDGE_SVC_PROBLEM";
                $nrdpCommand = $config->nrdpurl . '/?token=' . $config->nrdpsecret . 
                              '&cmd=submitcmd&command=' . $params->cmd . ';' . 
                              $firstLog->log_entry->channel->details->HOSTNAME . ';' . 
                              $service . ';2;0;0;' . $params->user . ';' . $params->comment;
            } else {
                $params->cmd = "ACKNOWLEDGE_HOST_PROBLEM";
                $nrdpCommand = $config->nrdpurl . '/?token=' . $config->nrdpsecret . 
                              '&cmd=submitcmd&command=' . $params->cmd . ';' . 
                              $firstLog->log_entry->channel->details->HOSTNAME . ';2;0;0;' . 
                              $params->user . ';' . $params->comment;
            }
            break;
            
        case "incident.unacknowledged":
        case "incident.escalated":
        case "incident.delegated":
        case "incident.resolved":
            // Remove the Service or Host acknowledgement and add a comment
            $params->comment = urlencode("ACK removed via PagerDuty");
            
            if (isset($service)) {
                $params->cmd = "REMOVE_SVC_ACKNOWLEDGEMENT";
                $nrdpCommand = $config->nrdpurl . '/?token=' . $config->nrdpsecret . 
                              '&cmd=submitcmd&command=' . $params->cmd . ';' . 
                              $firstLog->log_entry->channel->details->HOSTNAME . ';' . $service;
            } else {
                $params->cmd = "REMOVE_HOST_ACKNOWLEDGEMENT";
                $nrdpCommand = $config->nrdpurl . '/?token=' . $config->nrdpsecret . 
                              '&cmd=submitcmd&command=' . $params->cmd . ';' . 
                              $firstLog->log_entry->channel->details->HOSTNAME;
            }
            
            // Send the remove acknowledgement command
            sendCommand($nrdpCommand, $config);
            
            // Add a comment explaining why the ACK was removed
            $params->comment = urlencode("ACK removed via PagerDuty due to: " . $sourcePayload->event->event_type);
            $params->cmd = "ADD_HOST_COMMENT";
            $nrdpCommand = $config->nrdpurl . '/?token=' . $config->nrdpsecret . 
                          '&cmd=submitcmd&command=' . $params->cmd . ';' . 
                          $firstLog->log_entry->channel->details->HOSTNAME . ';false;' . 
                          $params->user . ';' . $params->comment;
            break;
            
        case "incident.reassigned":
            // Add comment showing who it has been assigned to
            $assignments = [];
            if (isset($sourcePayload->event->data->assignments) && is_array($sourcePayload->event->data->assignments)) {
                foreach ($sourcePayload->event->data->assignments as $assignment) {
                    $assignments[] = $assignment->assignee->summary ?? 'Unknown';
                }
            }
            $assignmentText = !empty($assignments) ? implode(', ', $assignments) : 'Unassigned';
            $params->comment = urlencode("Incident reassigned to: " . $assignmentText . " via PagerDuty");
            
            if (isset($service)) {
                $params->cmd = "ADD_SVC_COMMENT";
                $nrdpCommand = $config->nrdpurl . '/?token=' . $config->nrdpsecret . 
                              '&cmd=submitcmd&command=' . $params->cmd . ';' . 
                              $firstLog->log_entry->channel->details->HOSTNAME . ';' . 
                              $service . ';0;' . $params->user . ';' . $params->comment;
            } else {
                $params->cmd = "ADD_HOST_COMMENT";
                $nrdpCommand = $config->nrdpurl . '/?token=' . $config->nrdpsecret . 
                              '&cmd=submitcmd&command=' . $params->cmd . ';' . 
                              $firstLog->log_entry->channel->details->HOSTNAME . ';1;' . 
                              $params->user . ';' . $params->comment;
            }
            break;
            
        case "incident.priority_updated":
            // Show priority as a comment
            $priority = $sourcePayload->event->data->priority ?? 'Unknown';
            $params->comment = urlencode("Priority updated to: " . $priority . " via PagerDuty");
            
            if (isset($service)) {
                $params->cmd = "ADD_SVC_COMMENT";
                $nrdpCommand = $config->nrdpurl . '/?token=' . $config->nrdpsecret . 
                              '&cmd=submitcmd&command=' . $params->cmd . ';' . 
                              $firstLog->log_entry->channel->details->HOSTNAME . ';' . 
                              $service . ';0;' . $params->user . ';' . $params->comment;
            } else {
                $params->cmd = "ADD_HOST_COMMENT";
                $nrdpCommand = $config->nrdpurl . '/?token=' . $config->nrdpsecret . 
                              '&cmd=submitcmd&command=' . $params->cmd . ';' . 
                              $firstLog->log_entry->channel->details->HOSTNAME . ';1;' . 
                              $params->user . ';' . $params->comment;
            }
            break;
            
        case "incident.responder.added":
            // Add comment of who was added
            $responders = [];
            if (isset($sourcePayload->event->data->responders) && is_array($sourcePayload->event->data->responders)) {
                foreach ($sourcePayload->event->data->responders as $responder) {
                    $responders[] = $responder->user->summary ?? 'Unknown';
                }
            }
            $responderText = !empty($responders) ? implode(', ', $responders) : 'Unknown responder';
            $params->comment = urlencode("Responder added: " . $responderText . " via PagerDuty");
            
            if (isset($service)) {
                $params->cmd = "ADD_SVC_COMMENT";
                $nrdpCommand = $config->nrdpurl . '/?token=' . $config->nrdpsecret . 
                              '&cmd=submitcmd&command=' . $params->cmd . ';' . 
                              $firstLog->log_entry->channel->details->HOSTNAME . ';' . 
                              $service . ';0;' . $params->user . ';' . $params->comment;
            } else {
                $params->cmd = "ADD_HOST_COMMENT";
                $nrdpCommand = $config->nrdpurl . '/?token=' . $config->nrdpsecret . 
                              '&cmd=submitcmd&command=' . $params->cmd . ';' . 
                              $firstLog->log_entry->channel->details->HOSTNAME . ';1;' . 
                              $params->user . ';' . $params->comment;
            }
            break;
            
        case "incident.responder.replied":
            // Add reply from the responder in comment
            $replyContent = $sourcePayload->event->data->content ?? $sourcePayload->event->data->message ?? 'No reply content';
            $responderName = $sourcePayload->event->agent->summary ?? 'Unknown responder';
            $params->comment = urlencode("Responder reply from " . $responderName . " via PagerDuty: " . $replyContent);
            
            if (isset($service)) {
                $params->cmd = "ADD_SVC_COMMENT";
                $nrdpCommand = $config->nrdpurl . '/?token=' . $config->nrdpsecret . 
                              '&cmd=submitcmd&command=' . $params->cmd . ';' . 
                              $firstLog->log_entry->channel->details->HOSTNAME . ';' . 
                              $service . ';0;' . $params->user . ';' . $params->comment;
            } else {
                $params->cmd = "ADD_HOST_COMMENT";
                $nrdpCommand = $config->nrdpurl . '/?token=' . $config->nrdpsecret . 
                              '&cmd=submitcmd&command=' . $params->cmd . ';' . 
                              $firstLog->log_entry->channel->details->HOSTNAME . ';1;' . 
                              $params->user . ';' . $params->comment;
            }
            break;
            
        case "incident.status_update_posted":
            // Add status update as comment
            $statusContent = $sourcePayload->event->data->content ?? 'No status update content';
            $params->comment = urlencode("Status update via PagerDuty: " . $statusContent);
            
            if (isset($service)) {
                $params->cmd = "ADD_SVC_COMMENT";
                $nrdpCommand = $config->nrdpurl . '/?token=' . $config->nrdpsecret . 
                              '&cmd=submitcmd&command=' . $params->cmd . ';' . 
                              $firstLog->log_entry->channel->details->HOSTNAME . ';' . 
                              $service . ';0;' . $params->user . ';' . $params->comment;
            } else {
                $params->cmd = "ADD_HOST_COMMENT";
                $nrdpCommand = $config->nrdpurl . '/?token=' . $config->nrdpsecret . 
                              '&cmd=submitcmd&command=' . $params->cmd . ';' . 
                              $firstLog->log_entry->channel->details->HOSTNAME . ';1;' . 
                              $params->user . ';' . $params->comment;
            }
            break;
            
        default:
            if ($config->debug) {
                fwrite($debugLog, "Unhandled event type: " . $sourcePayload->event->event_type . "\n");
            }
            http_response_code(200);
            die('Event type not handled');
    }
    
    // Send the command
    if (isset($nrdpCommand)) {
        if ($config->debug) {
            fwrite($debugLog, "\n==== NRDP Command ==== \n");
            fwrite($debugLog, $nrdpCommand . "\n");
            fwrite($debugLog, "\n==== Sending NRDP ==== \n");
        }
        
        sendCommand($nrdpCommand, $config);
    }
    
    // Final debug logging
    if ($config->debug) {
        fwrite($debugLog, "\n==== Processing complete ==== \n");
        fwrite($debugLog, "Event type: " . $sourcePayload->event->event_type . "\n");
        fwrite($debugLog, "Incident ID: " . $incidentId . "\n");
        fwrite($debugLog, "Service: " . ($service ?? 'N/A') . "\n");
        fwrite($debugLog, "Host: " . $firstLog->log_entry->channel->details->HOSTNAME . "\n");
    }
    
} catch (Exception $e) {
    if ($config->debug) {
        fwrite($debugLog, "Error processing incident: " . $e->getMessage() . "\n");
    }
    http_response_code(500);
    die('Internal server error: ' . $e->getMessage());
}

// Success response
http_response_code(200);
echo "OK";
