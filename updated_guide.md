
# PagerDuty to Nagios Two-Way Integration Installation Guide v1.2.0

## Overview

This guide covers the complete setup of a bi-directional integration between PagerDuty and Nagios using PD2Nagiosv3 v1.2.0. The integration provides:

- **Nagios → PagerDuty**: Send alerts and notifications to PagerDuty
- **PagerDuty → Nagios**: Sync incident actions (acknowledgements, comments, assignments) back to Nagios

## Background

Originally this script worked with the standard PagerDuty Nagios integration, however that integration is deprecated. The pdagent doesn't work on modern Linux distributions and PagerDuty has publicly [deprecated](https://github.com/PagerDuty/pdagent?tab=readme-ov-file#notice) the product, referring users to [go-pdagent](https://github.com/PagerDuty/go-pdagent) which was never finished and has been silently abandoned (no commits for 3+ years).

The original pdagent and Perl script used a custom event format specifically made for the Nagios integration, which creates problems with newer PagerDuty tech stack like Event Orchestration.

**Solution**: Martin Stone created an excellent pdagent alternative in Python packaged as a Docker image that just works. This guide uses that solution.

## What's New in v1.2.0

### Enhanced Event Support
- **New PagerDuty Event Types**: Support for `incident.reassigned`, `incident.priority_updated`, `incident.responder.added`, `incident.responder.replied`, `incident.status_update_posted`
- **Better Integration**: All PagerDuty incident activities now sync to Nagios comments

### Improved Alert Script
- **Enhanced Security**: Input sanitization and validation
- **Environment Configuration**: Flexible configuration via environment variables
- **Better Error Handling**: Comprehensive logging and debug capabilities
- **Docker Health Checks**: Automatic container status verification

### Code Quality Improvements
- **Modern PHP Syntax**: Array shorthand, null coalescing, strict comparison
- **Enhanced Error Handling**: Proper HTTP status codes and exception handling
- **Comprehensive Documentation**: PHPDoc comments and better code organization
- **Security Enhancements**: SSL verification and improved signature validation

## Prerequisites

### System Requirements
- **PHP**: 8.0+ (tested with PHP 8.3.6, 8.1.2, 8.0.8)
- **Extensions**: cURL, JSON
- **File Permissions**: Write access for debug logging and IP caching
- **Docker**: For PDAltAgent container

### Nagios Installation
* A currently working Nagios installation (any of these options):
    * Nagios XI (Commercial)  
    * Nagios Core (Open Source) 
    * Nagios CSP (Freemium)

### Docker Installation
* Ability to install Docker on the Linux host
    * Install Docker per the documentation at https://docs.docker.com/engine/install/

## Installation Steps

### Step 1: Configure PagerDuty Integration

#### Option 1: Setup a Service in PagerDuty (Not Recommended)
This option requires an individual contact for each service in PagerDuty. It's easy to implement but gets messy over time.

1. Create a PagerDuty Service or identify an existing service to use
2. Add "Events API V2" integration
3. Copy the integration key

#### Option 2: Setup Event Orchestration (Recommended)
This option allows you to have a single contact in Nagios used to send events to PagerDuty. This is the better option for easy implementation and growth over time.

1. Create an event orchestration via the AIOps menu
2. Copy the integration key from the integration screen

### Step 2: Install PDAltAgent

Install [PDAltAgent](https://github.com/martindstone/PDaltagent) with Docker:

```bash
wget https://raw.githubusercontent.com/Nozlaf/PDaltagent/refs/heads/fix-docker-compose.yml/docker-compose.yml
docker compose up -d
usermod -aG docker nagios
```

### Step 3: Install Nagios to PagerDuty Integration

#### 3.1. Download and Install the Alert Script

```bash
# Download the latest v1.2.0 script
wget https://raw.githubusercontent.com/Nozlaf/PD2Nagiosv3/v1.2.0/send_PD_alert.sh

# Move to Nagios libexec directory
sudo mv send_PD_alert.sh /usr/local/nagios/libexec/send_PD_alert.sh
sudo chmod 550 /usr/local/nagios/libexec/send_PD_alert.sh
sudo chown nagios:nagios /usr/local/nagios/libexec/send_PD_alert.sh
```

#### 3.2. Configure Environment Variables (Optional but Recommended)

Create a configuration file:

```bash
# Download example configuration
wget https://raw.githubusercontent.com/Nozlaf/PD2Nagiosv3/v1.2.0/send_PD_alert.conf.example

# Copy and customize
cp send_PD_alert.conf.example /usr/local/nagios/etc/send_PD_alert.conf

# Edit the configuration file
sudo nano /usr/local/nagios/etc/send_PD_alert.conf
```

Update the configuration with your environment:

```bash
# Nagios system name (displayed in PagerDuty)
export NAGIOS_NAME="Production Nagios Core"

# Nagios extinfo URL (for PagerDuty incident links)
export EXTINFO_URL="https://nagios.company.com/nagios/cgi-bin/extinfo.cgi"

# Docker container name running pdaltagent
export DOCKER_CONTAINER="pdaltagent_pdagentd"

# Debug log file path
export DEBUG_LOG="/var/log/nagios/pd2nagios_debug.log"

# Enable debug mode (set to "true" to enable)
export DEBUG="false"
```

Source the configuration in your Nagios environment:

```bash
# Add to Nagios startup script or environment
echo "source /usr/local/nagios/etc/send_PD_alert.conf" >> /etc/environment
```

#### 3.3. Update Nagios Configuration

Download and install the PagerDuty configuration:

```bash
wget https://raw.githubusercontent.com/Nozlaf/PD2Nagiosv3/v1.2.0/PagerDuty.cfg
sudo mv PagerDuty.cfg /usr/local/nagios/etc/objects/
```

Edit `/usr/local/nagios/etc/nagios.cfg` to include the PagerDuty configuration:

```bash
# Add this line to nagios.cfg
cfg_file=/usr/local/nagios/etc/objects/PagerDuty.cfg
```

#### 3.4. Configure Contact Groups

Add the PagerDuty user to your contact groups. For example:

```bash
define contactgroup {
    contactgroup_name       admins
    alias                   Nagios Administrators
    members                 nagiosadmin,pagerduty
}
```

### Step 4: Install PagerDuty to Nagios Integration

#### 4.1. Download Integration Files

```bash
# Download v1.2.0 integration files
wget https://raw.githubusercontent.com/Nozlaf/PD2Nagiosv3/v1.2.0/PD2Nagiosv3_config.php
wget https://raw.githubusercontent.com/Nozlaf/PD2Nagiosv3/v1.2.0/PD2Nagiosv3_pagerduty.php

# Move to web server directory
sudo mv PD2Nagiosv3_config.php /var/www/html/
sudo mv PD2Nagiosv3_pagerduty.php /var/www/html/
```

#### 4.2. Configure the Integration

Edit `PD2Nagiosv3_config.php`:

```php
// PagerDuty API Configuration
$config->apiKey = 'your-readonly-api-key';  // Read-only API key
$config->apiendpoint = 'api.pagerduty.com'; // Use 'api.eu.pagerduty.com' for EU

// Security Configuration
$config->securemode = true; // Enable IP filtering (recommended)
$config->webhookValidate = true; // Enable webhook signature validation

// Webhook Security
$config->webhooksecrets = [
    "key1" => "your-webhook-secret-1",
    "key2" => "your-webhook-secret-2" // Optional: multiple secrets
];

// Integration Method
$config->method = "NRDP"; // Use "FILE" for external command file

// NRDP Configuration
$config->nrdpurl = "http://nagioshost.internal/nrdp";
$config->nrdpsecret = "your-nrdp-secret";

// Debug Configuration
$config->debug = true; // Enable for troubleshooting
```

#### 4.3. Set Up Logging

Create debug log file with proper permissions:

```bash
# Create log file
sudo touch /var/www/html/PD2Nagiosv3_debug.log
sudo touch /var/www/html/ip_cache.json

# Set permissions (adjust for your security requirements)
sudo chmod 666 /var/www/html/PD2Nagiosv3_debug.log
sudo chmod 666 /var/www/html/ip_cache.json
sudo chown www-data:www-data /var/www/html/PD2Nagiosv3_debug.log
sudo chown www-data:www-data /var/www/html/ip_cache.json
```

### Step 5: Configure PagerDuty Webhook

1. In PagerDuty, go to **Integrations** → **Generic Webhooks (v3)**
2. Click **"+New Webhook"**
3. Configure the webhook:
   - **Webhook URL**: `https://your-server.com/PD2Nagiosv3_pagerduty.php`
   - **Scope**: Choose based on your needs (Account, Team, or Service)
4. Copy the webhook secret and add it to your `PD2Nagiosv3_config.php`

## Command Definitions

### For Nagios XI & CSP

Follow the standard PagerDuty guide but use these updated commands:

#### Host Command
```bash
$USER1$/send_PD_alert.sh -k $CONTACTPAGER$ -o "$HOSTNAME$" -t "$HOSTSTATE$" \
  -f HOSTNAME="$HOSTNAME$" -f HOSTSTATE="$HOSTSTATE$" \
  -f HOSTDISPLAYNAME="$HOSTDISPLAYNAME$" -f HOSTPROBLEMID="$HOSTPROBLEMID$" \
  -f HOSTEVENTID="$HOSTEVENTID" -i "$HOSTNAME$"
```

#### Service Command
```bash
$USER1$/send_PD_alert.sh -k $CONTACTPAGER$ -o "$HOSTNAME$" -s "$SERVICEDESC$" \
  -t "$SERVICESTATE$" -f SERVICEDESC='"$SERVICEDESC$"' \
  -f SERVICESTATE="$SERVICESTATE$" -f SERVICEOUTPUT='"$SERVICEOUTPUT$"' \
  -f HOSTNAME="$HOSTNAME$" -f HOSTSTATE="$HOSTSTATE$" \
  -f HOSTDISPLAYNAME="$HOSTDISPLAYNAME$" -f SERVICEEVENTID="$SERVICEEVENTID$" \
  -f SERVICEOUTPUT='"$SERVICEOUTPUT$"' -i '"$HOSTNAME$_$SERVICEDESC$"'
```

### For Nagios Core

Download the pre-configured files from the repository and copy them to your Nagios configuration folder.

## Supported Event Types

The integration now supports these PagerDuty event types:

- `incident.annotated` - Add comments to Nagios services/hosts
- `incident.acknowledged` - Acknowledge Nagios problems
- `incident.unacknowledged` - Remove acknowledgements
- `incident.escalated` - Remove acknowledgements
- `incident.delegated` - Remove acknowledgements
- `incident.resolved` - Remove acknowledgements
- `incident.reassigned` - Add comment showing assignment changes
- `incident.priority_updated` - Add comment showing priority updates
- `incident.responder.added` - Add comment when responders are added
- `incident.responder.replied` - Add responder replies as comments
- `incident.status_update_posted` - Add status updates as comments
- `pagey.ping` - Health check endpoint

## Testing the Integration

### Test Nagios → PagerDuty
1. Create a test service or host alert in Nagios
2. Verify the alert appears in PagerDuty
3. Check that the incident resolves when the service/host recovers

### Test PagerDuty → Nagios
1. Create an incident in PagerDuty
2. Acknowledge the incident in PagerDuty
3. Verify the acknowledgement appears in Nagios
4. Test other actions (reassign, add responders, etc.)

### Debug Mode
Enable debug logging by setting `$config->debug = true;` in the configuration file. Check the debug log for detailed information about webhook processing.

## Troubleshooting

### Common Issues

1. **Webhook Not Receiving Events**
   - Check web server logs
   - Verify webhook URL is accessible
   - Check IP filtering settings

2. **Commands Not Reaching Nagios**
   - Verify NRDP is running and accessible
   - Check NRDP token and URL
   - Review debug logs for command details

3. **Permission Issues**
   - Ensure web server can write to log files
   - Check file ownership and permissions
   - Verify Docker container permissions

### Debug Commands

```bash
# Test webhook endpoint
curl -X POST https://your-server.com/PD2Nagiosv3_pagerduty.php

# Check debug logs
tail -f /var/www/html/PD2Nagiosv3_debug.log

# Test alert script
/usr/local/nagios/libexec/send_PD_alert.sh --help
```

## Security Considerations

- **API Keys**: Use read-only API keys when possible
- **Webhook Secrets**: Enable webhook signature validation
- **IP Filtering**: Enable PagerDuty IP safelist filtering
- **File Permissions**: Use appropriate file permissions for log files
- **HTTPS**: Use HTTPS for webhook endpoints in production

## Support

For issues and questions:
- Check the debug logs for detailed error information
- Review the [main README](README.md) for additional documentation
- Ensure you're using the latest v1.2.0 release

---

**Note**: This guide is for PD2Nagiosv3 v1.2.0. For older versions, refer to the appropriate documentation.
