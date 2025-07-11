#!/usr/bin/env bash
set -o errexit
set -o pipefail
set -o nounset

# Configuration - Update these values for your environment
readonly NAGIOS_NAME="${NAGIOS_NAME:-"Nagios - Update NAGIOS_NAME environment variable"}"
readonly EXTINFO_URL="${EXTINFO_URL:-"http://nagios.local/nagios/cgi-bin/extinfo.cgi"}"
readonly DOCKER_CONTAINER="${DOCKER_CONTAINER:-"pdaltagent_pdagentd"}"
readonly DEBUG_LOG="${DEBUG_LOG:-"/tmp/sendpdevent.log"}"
readonly SCRIPT_VERSION="1.2"

# Colors for output (if supported)
readonly RED='\033[0;31m'
readonly GREEN='\033[0;32m'
readonly YELLOW='\033[1;33m'
readonly NC='\033[0m' # No Color

# Logging function
log_message() {
    local level="$1"
    shift
    local message="$*"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    
    case "$level" in
        "ERROR")
            echo -e "${RED}[ERROR]${NC} $timestamp: $message" >&2
            ;;
        "WARN")
            echo -e "${YELLOW}[WARN]${NC} $timestamp: $message" >&2
            ;;
        "INFO")
            echo -e "${GREEN}[INFO]${NC} $timestamp: $message"
            ;;
        "DEBUG")
            if [[ "${DEBUG:-false}" == "true" ]]; then
                echo "[DEBUG] $timestamp: $message"
            fi
            ;;
    esac
    
    # Always log to debug file if enabled
    if [[ "${DEBUG:-false}" == "true" ]]; then
        echo "[$level] $timestamp: $message" >> "$DEBUG_LOG"
    fi
}

# Error handling function
error_exit() {
    log_message "ERROR" "$1"
    exit 1
}

# Validate required parameters
validate_parameters() {
    local missing_params=()
    
    [[ -z "${k_arg:-}" ]] && missing_params+=("routing key (-k)")
    [[ -z "${hostname:-}" ]] && missing_params+=("hostname (-o)")
    [[ -z "${t_arg:-}" ]] && missing_params+=("notification type (-t)")
    
    if [[ ${#missing_params[@]} -gt 0 ]]; then
        error_exit "Missing required parameters: ${missing_params[*]}"
    fi
    
    # Validate URL format
    if [[ ! "$EXTINFO_URL" =~ ^https?:// ]]; then
        error_exit "Invalid EXTINFO_URL format: $EXTINFO_URL"
    fi
}

# Sanitize input to prevent command injection
sanitize_input() {
    local input="$1"
    # Remove any characters that could be used for command injection
    echo "$input" | sed 's/[;&|`$()<>]//g'
}

# Check if Docker container is running
check_docker_container() {
    if ! docker ps --format "table {{.Names}}" | grep -q "^${DOCKER_CONTAINER}$"; then
        error_exit "Docker container '$DOCKER_CONTAINER' is not running"
    fi
}

# Display script information
show_info() {
    log_message "INFO" "PD2Nagiosv3 Alert Script v$SCRIPT_VERSION"
    log_message "INFO" "Nagios Name: $NAGIOS_NAME"
    log_message "INFO" "ExtInfo URL: $EXTINFO_URL"
    log_message "INFO" "Docker Container: $DOCKER_CONTAINER"
    if [[ "${DEBUG:-false}" == "true" ]]; then
        log_message "INFO" "Debug logging enabled: $DEBUG_LOG"
    fi
}

# Main script logic
main() {
    local severity="critical"
    local d_arg=""
    local f_arg=""
    local servicename=""
    local k_arg=""
    local t_arg=""
    local i_arg=""
    local hostname=""
    local c_arg=""
    
    # Parse command line arguments
    while [[ "$#" -gt 0 ]]; do
        case $1 in
            -k)
                k_arg=$(sanitize_input "$2")
                shift 2
                ;;
            -d)
                d_arg=$(sanitize_input "$2")
                shift 2
                ;;
            -c)
                c_arg=$(sanitize_input "$2")
                shift 2
                ;;
            -f)
                local field_value=$(sanitize_input "$2")
                if [[ -z "$f_arg" ]]; then
                    f_arg="-f $field_value"
                else
                    f_arg="$f_arg -f $field_value"
                fi
                shift 2
                ;;
            -t)
                case $2 in
                    ACKNOWLEDGEMENT)
                        t_arg="-t acknowledge"
                        severity="critical"
                        ;;
                    DOWN|PROBLEM|CRITICAL|CUSTOM)
                        t_arg="-t trigger"
                        severity="critical"
                        ;;
                    UP|RECOVERY|OK)
                        t_arg="-t resolve"
                        ;;
                    WARNING)
                        t_arg="-t trigger"
                        severity="warning"
                        ;;
                    *)
                        t_arg="-t $(sanitize_input "$2")"
                        ;;
                esac
                shift 2
                ;;
            -i)
                i_arg="-i $(sanitize_input "$2")"
                shift 2
                ;;
            -s)
                servicename=$(sanitize_input "$2")
                shift 2
                ;;
            -o)
                hostname=$(sanitize_input "$2")
                shift 2
                ;;
            --debug)
                DEBUG="true"
                shift
                ;;
            -h|--help)
                show_usage
                exit 0
                ;;
            --version)
                echo "PD2Nagiosv3 Alert Script v$SCRIPT_VERSION"
                exit 0
                ;;
            *)
                error_exit "Unknown parameter: $1"
                ;;
        esac
    done
    
    # Validate parameters
    validate_parameters
    
    # Show script info in debug mode
    if [[ "${DEBUG:-false}" == "true" ]]; then
        show_info
    fi
    
    # Check Docker container
    check_docker_container
    
    # Build description and URL
    local description
    local url
    
    if [[ -z "$servicename" ]]; then
        description="${severity} issue with ${hostname} ${d_arg}"
        url="${EXTINFO_URL}?type=1&host=${hostname}"
        log_message "DEBUG" "Processing HOST alert for: $hostname"
    else
        description="${severity} issue with ${servicename} on ${hostname} ${d_arg}"
        url="${EXTINFO_URL}?type=2&host=${hostname}&service=${servicename}"
        log_message "DEBUG" "Processing SERVICE alert for: $hostname/$servicename"
    fi
    
    # Build pd-send command
    local pd_send_cmd="docker exec ${DOCKER_CONTAINER} pd-send"
    pd_send_cmd+=" -c \"${NAGIOS_NAME}\""
    pd_send_cmd+=" -u \"${url}\""
    pd_send_cmd+=" -k ${k_arg}"
    pd_send_cmd+=" ${t_arg}"
    pd_send_cmd+=" -s \"${severity}\""
    pd_send_cmd+=" -d \"${description}\""
    
    # Add optional parameters
    [[ -n "$c_arg" ]] && pd_send_cmd+=" -c \"${c_arg}\""
    [[ -n "$f_arg" ]] && pd_send_cmd+=" ${f_arg}"
    [[ -n "$i_arg" ]] && pd_send_cmd+=" ${i_arg}"
    
    # Log the command in debug mode
    log_message "DEBUG" "Executing: $pd_send_cmd"
    
    # Execute the command
    if eval "$pd_send_cmd"; then
        log_message "INFO" "Successfully sent PagerDuty alert"
    else
        error_exit "Failed to send PagerDuty alert"
    fi
}

# Usage information
show_usage() {
    cat << EOF
PD2Nagiosv3 Alert Script v$SCRIPT_VERSION

Usage: $0 [OPTIONS]

Required Options:
  -k <key>              PagerDuty routing key (Contact pager)
  -o <hostname>         Host name
  -t <type>             Notification type (UP, DOWN, PROBLEM, RECOVERY, WARNING, CRITICAL, etc.)

Optional Options:
  -s <service>          Service name (for service alerts)
  -d <description>      Description/Summary
  -c <client>           Client name
  -f <field>            Custom field (can be used multiple times)
  -i <incident>         Nagios incident key
  --debug               Enable debug logging
  --version             Show version information
  -h, --help            Show this help message

Environment Variables:
  NAGIOS_NAME           Nagios system name (default: "Nagios - Update NAGIOS_NAME environment variable")
  EXTINFO_URL           Nagios extinfo URL (default: "http://nagios.local/nagios/cgi-bin/extinfo.cgi")
  DOCKER_CONTAINER      Docker container name (default: "pdaltagent_pdagentd")
  DEBUG_LOG             Debug log file path (default: "/tmp/sendpdevent.log")
  DEBUG                 Set to "true" to enable debug mode

Examples:
  # Service alert
  $0 -k "your-routing-key" -o "webserver01" -s "HTTP" -t "CRITICAL" -d "Service is down"

  # Host alert  
  $0 -k "your-routing-key" -o "webserver01" -t "DOWN" -d "Host is unreachable"

  # With custom fields
  $0 -k "your-routing-key" -o "webserver01" -s "HTTP" -t "WARNING" \\
     -f "SERVICEDESC='HTTP'" -f "SERVICEOUTPUT='Connection timeout'" \\
     -i "webserver01_HTTP"

EOF
}

# Run main function
main "$@"
