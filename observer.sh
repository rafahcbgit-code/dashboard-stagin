#!/usr/bin/env bash

################################################################################
# OBSERVER.SH - ChatBerry Server Monitoring Script
################################################################################
#
# PURPOSE:
#   Monitor your Laravel server including Redis queues, MySQL, PHP-FPM,
#   Supervisor, and system resources (CPU, Memory, Disk, Network)
#
# USAGE:
#   1. Run in screen: screen -S observer ./observer.sh
#   2. Detach from screen: Press Ctrl+A then D
#   3. Reattach to screen: screen -r observer
#   4. Stop observer: Press Ctrl+C
#
# LOGS LOCATION:
#   All logs are saved in: /var/log/observer/
#   - metrics.log      : All metrics with timestamps
#   - queues.log       : Queue depth tables
#   - alerts.log       : Only warnings and errors
#   - latest.txt       : Latest snapshot
#
################################################################################

set -e

################################################################################
# CONFIGURATION - EDIT THESE VALUES
################################################################################

CHECK_INTERVAL=60
REDISCOVERY_INTERVAL=300

# MySQL Connection
MYSQL_USER="cbdash_admin"
MYSQL_PASS='64o11!=6$Qpb'
MYSQL_HOST="127.0.0.1"
MYSQL_PORT="3306"

# Redis Connection
REDIS_HOST="127.0.0.1"
REDIS_PORT="6379"
REDIS_DB="0"
REDIS_PREFIX="chatberry_database_"

# PHP-FPM Status URL (set to empty "" to disable)
FPM_STATUS_URL="https://127.0.0.1/fpm-status"

# Log Directory
LOG_DIR="/var/log/observer"

################################################################################
# INTERNAL VARIABLES
################################################################################

METRICS_LOG="$LOG_DIR/metrics.log"
QUEUES_LOG="$LOG_DIR/queues.log"
ALERTS_LOG="$LOG_DIR/alerts.log"
LATEST_FILE="$LOG_DIR/latest.txt"
STATE_FILE="$LOG_DIR/queue_state.txt"
DISCOVERED_QUEUES="$LOG_DIR/discovered_queues.txt"

SCRIPT_PID=$$
HOSTNAME=$(hostname 2>/dev/null || echo "unknown")

################################################################################
# HELPER FUNCTIONS
################################################################################

get_timestamp() {
    date '+%Y-%m-%d %H:%M:%S'
}

print_color() {
    local color=$1
    shift
    case $color in
        green)  echo -e "\033[0;32m$*\033[0m" ;;
        yellow) echo -e "\033[0;33m$*\033[0m" ;;
        red)    echo -e "\033[0;31m$*\033[0m" ;;
        blue)   echo -e "\033[0;34m$*\033[0m" ;;
        cyan)   echo -e "\033[0;36m$*\033[0m" ;;
        *)      echo "$*" ;;
    esac
}

log_to_file() {
    local logfile=$1
    shift
    echo "[$(get_timestamp)] $*" >> "$logfile"
}

log_info() {
    print_color green "[INFO] $*"
    log_to_file "$METRICS_LOG" "[INFO] $*"
}

log_warn() {
    print_color yellow "[WARN] $*"
    log_to_file "$ALERTS_LOG" "[WARN] $*"
}

log_error() {
    print_color red "[ERROR] $*"
    log_to_file "$ALERTS_LOG" "[ERROR] $*"
}

################################################################################
# SETUP
################################################################################

setup_log_directory() {
    if [ ! -d "$LOG_DIR" ]; then
        mkdir -p "$LOG_DIR"
        chmod 755 "$LOG_DIR"
    fi
    touch "$METRICS_LOG" "$QUEUES_LOG" "$ALERTS_LOG" "$LATEST_FILE"
    chmod 644 "$METRICS_LOG" "$QUEUES_LOG" "$ALERTS_LOG" "$LATEST_FILE"
}

print_banner() {
    clear
    print_color blue "════════════════════════════════════════════════════════════════"
    print_color blue "  OBSERVER.SH - ChatBerry Server Monitoring"
    print_color blue "════════════════════════════════════════════════════════════════"
    echo ""
    echo "  Host:           $HOSTNAME"
    echo "  Script PID:     $SCRIPT_PID"
    echo "  Started:        $(get_timestamp)"
    echo "  Check Interval: ${CHECK_INTERVAL}s"
    echo "  Redis Prefix:   ${REDIS_PREFIX}"
    echo ""
    echo "  Log Directory:  $LOG_DIR"
    echo ""
    print_color cyan "  Log Files:"
    echo "    • metrics.log      - All metrics with timestamps"
    echo "    • queues.log       - Queue depth table"
    echo "    • alerts.log       - Warnings and errors"
    echo "    • latest.txt       - Latest snapshot"
    echo ""
    print_color blue "════════════════════════════════════════════════════════════════"
    echo ""
    print_color yellow "  Screen Commands:"
    echo "    • Detach:  Ctrl+A then D"
    echo "    • Stop:    Ctrl+C"
    echo ""
    print_color blue "════════════════════════════════════════════════════════════════"
    echo ""
}

################################################################################
# REDIS FUNCTIONS
################################################################################

redis_cmd() {
    redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" -n "$REDIS_DB" "$@" 2>/dev/null || echo ""
}

discover_queues() {
    log_info "Discovering queues with prefix: ${REDIS_PREFIX}..."
    
    local temp_file="$LOG_DIR/scan_temp.txt"
    : > "$temp_file"
    
    # Use KEYS instead of SCAN (simpler, works on all Redis versions)
    redis_cmd keys "${REDIS_PREFIX}queues:*" > "$temp_file" 2>/dev/null || true
    
    if [ -s "$temp_file" ]; then
        # Extract queue names, removing prefix and suffixes
        # chatberry_database_queues:default:notify -> default
        # chatberry_database_queues:webhook-high -> webhook-high
        grep "${REDIS_PREFIX}queues:" "$temp_file" | \
        sed "s|^${REDIS_PREFIX}queues:||" | \
        sed 's/:notify$//' | \
        sed 's/:reserved$//' | \
        sed 's/:delayed$//' | \
        sort -u > "$DISCOVERED_QUEUES"
        
        local count=$(wc -l < "$DISCOVERED_QUEUES")
        log_info "Discovered $count queue(s)"
        
        if [ "$count" -gt 0 ]; then
            while read -r q; do
                log_info "  - Queue: $q"
            done < "$DISCOVERED_QUEUES"
        fi
    else
        log_warn "No queues found in Redis with prefix ${REDIS_PREFIX}"
        : > "$DISCOVERED_QUEUES"
    fi
    
    rm -f "$temp_file"
}

get_redis_info() {
    local info=$(redis_cmd INFO 2>/dev/null)
    
    if [ -z "$info" ]; then
        echo "Redis: UNAVAILABLE"
        return 1
    fi
    
    local mem=$(echo "$info" | grep "^used_memory:" | cut -d: -f2 | tr -d '\r' | head -1)
    local clients=$(echo "$info" | grep "^connected_clients:" | cut -d: -f2 | tr -d '\r' | head -1)
    local ops=$(echo "$info" | grep "^instantaneous_ops_per_sec:" | cut -d: -f2 | tr -d '\r' | head -1)
    
    local mem_mb=$((${mem:-0} / 1024 / 1024))
    
    echo "Redis: Memory=${mem_mb}MB | Clients=${clients:-0} | Ops/sec=${ops:-0}"
}

check_queues() {
    [ ! -f "$DISCOVERED_QUEUES" ] && return
    
    local timestamp=$(get_timestamp)
    local temp_log="$QUEUES_LOG.tmp"
    : > "$temp_log"
    
    echo "════════════════════════════════════════════════════════════════════════════════" >> "$temp_log"
    echo "Queue Status at $timestamp" >> "$temp_log"
    echo "════════════════════════════════════════════════════════════════════════════════" >> "$temp_log"
    printf "%-30s %10s %10s %10s %10s %10s\n" \
        "Queue Name" "Main" "Delayed" "Reserved" "Total" "Change" >> "$temp_log"
    echo "────────────────────────────────────────────────────────────────────────────────" >> "$temp_log"
    
    declare -A prev_totals
    if [ -f "$STATE_FILE" ]; then
        while IFS='=' read -r queue total; do
            prev_totals[$queue]=$total
        done < "$STATE_FILE"
    fi
    
    local new_state="$STATE_FILE.new"
    : > "$new_state"
    
    while read -r queue; do
        [ -z "$queue" ] && continue
        
        # Get queue counts with prefix
        # Main queue is always a LIST
        local main=$(redis_cmd LLEN "${REDIS_PREFIX}queues:$queue" 2>/dev/null | tr -d '\r')
        
        # Delayed/Notify can be either LIST or sorted set
        # ChatBerry uses :notify as a LIST
        local delayed=$(redis_cmd LLEN "${REDIS_PREFIX}queues:${queue}:notify" 2>/dev/null | tr -d '\r')
        if [ -z "$delayed" ] || [ "$delayed" = "0" ] || [ "$delayed" = "(nil)" ]; then
            # Try as sorted set (standard Laravel)
            delayed=$(redis_cmd ZCARD "${REDIS_PREFIX}queues:${queue}:delayed" 2>/dev/null | tr -d '\r')
        fi
        
        # Reserved is usually a sorted set, but check as list too
        local reserved=$(redis_cmd ZCARD "${REDIS_PREFIX}queues:${queue}:reserved" 2>/dev/null | tr -d '\r')
        if [ -z "$reserved" ] || [ "$reserved" = "0" ] || [ "$reserved" = "(nil)" ]; then
            reserved=$(redis_cmd LLEN "${REDIS_PREFIX}queues:${queue}:reserved" 2>/dev/null | tr -d '\r')
        fi
        
        # Handle empty or nil responses
        [ -z "$main" ] || [ "$main" = "(nil)" ] && main=0
        [ -z "$delayed" ] || [ "$delayed" = "(nil)" ] && delayed=0
        [ -z "$reserved" ] || [ "$reserved" = "(nil)" ] && reserved=0
        
        local total=$((main + delayed + reserved))
        local prev=${prev_totals[$queue]:-$total}
        local change=$((total - prev))
        
        echo "${queue}=${total}" >> "$new_state"
        
        local change_display=""
        if [ $change -gt 0 ]; then
            change_display="+$change"
        elif [ $change -lt 0 ]; then
            change_display="$change"
        else
            change_display="0"
        fi
        
        printf "%-30s %10s %10s %10s %10s %10s\n" \
            "$queue" "$main" "$delayed" "$reserved" "$total" "$change_display" >> "$temp_log"
        
        if [ "$total" -ge 1000 ] 2>/dev/null; then
            log_warn "High queue backlog: '$queue' has $total jobs"
        fi
        
        if [ "$reserved" -gt 0 ] 2>/dev/null && [ "$main" -eq 0 ] 2>/dev/null && [ "$delayed" -eq 0 ] 2>/dev/null; then
            log_warn "Stuck jobs detected: '$queue' has $reserved reserved jobs only"
        fi
        
    done < "$DISCOVERED_QUEUES"
    
    echo "════════════════════════════════════════════════════════════════════════════════" >> "$temp_log"
    
    mv "$new_state" "$STATE_FILE"
    mv "$temp_log" "$QUEUES_LOG"
    
    echo "Queues: See $QUEUES_LOG for detailed table"
}

################################################################################
# MYSQL FUNCTIONS
################################################################################

get_mysql_info() {
    local cmd="mysql -h $MYSQL_HOST -P $MYSQL_PORT -u $MYSQL_USER"
    [ -n "$MYSQL_PASS" ] && cmd="$cmd -p$MYSQL_PASS"
    
    local query="SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Threads_running','Slow_queries');"
    local result=$($cmd -N -B -e "$query" 2>/dev/null || echo "")
    
    if [ -z "$result" ]; then
        echo "MySQL: UNAVAILABLE"
        return 1
    fi
    
    local conn=$(echo "$result" | grep "Threads_connected" | awk '{print $2}')
    local running=$(echo "$result" | grep "Threads_running" | awk '{print $2}')
    local slow=$(echo "$result" | grep "Slow_queries" | awk '{print $2}')
    
    echo "MySQL: Connected=${conn:-0} | Running=${running:-0} | Slow=${slow:-0}"
    
    if [ "${running:-0}" -ge 50 ] 2>/dev/null; then
        log_warn "MySQL has high running threads: $running (threshold: 50)"
    fi
}

################################################################################
# PHP-FPM FUNCTIONS
################################################################################

get_fpm_info() {
    [ -z "$FPM_STATUS_URL" ] && return
    
    if ! command -v curl >/dev/null 2>&1; then
        echo "PHP-FPM: curl not installed"
        return 1
    fi
    
    local status=$(curl -k -fsS --max-time 3 "$FPM_STATUS_URL" 2>/dev/null || echo "")
    
    if [ -z "$status" ]; then
        echo "PHP-FPM: UNAVAILABLE"
        return 1
    fi
    
    local active=$(echo "$status" | grep "active processes:" | awk '{print $NF}')
    local idle=$(echo "$status" | grep "idle processes:" | awk '{print $NF}')
    local total=$(echo "$status" | grep "total processes:" | awk '{print $NF}')
    
    echo "PHP-FPM: Active=${active:-0} | Idle=${idle:-0} | Total=${total:-0}"
}

################################################################################
# SUPERVISOR FUNCTIONS
################################################################################

get_supervisor_info() {
    if ! command -v supervisorctl >/dev/null 2>&1; then
        echo "Supervisor: Not installed"
        return 1
    fi
    
    local status=$(supervisorctl status 2>/dev/null || echo "")
    
    if [ -z "$status" ]; then
        echo "Supervisor: UNAVAILABLE"
        return 1
    fi
    
    local running=$(echo "$status" | grep -c "RUNNING" || echo "0")
    local stopped=$(echo "$status" | grep -c "STOPPED" || echo "0")
    local fatal=$(echo "$status" | grep -c "FATAL" || echo "0")
    
    echo "Supervisor: Running=${running} | Stopped=${stopped} | Fatal=${fatal}"
    
    if [ "$fatal" -gt 0 ] 2>/dev/null; then
        log_warn "Supervisor has $fatal FATAL process(es)"
    fi
}

################################################################################
# SYSTEM METRICS
################################################################################

get_system_info() {
    local load=$(cat /proc/loadavg | awk '{print $1","$2","$3}')
    local mem=$(free -m | awk 'NR==2{printf "%d/%d MB", $3, $2}')
    local mem_avail=$(free -m | awk 'NR==2{print $7}')
    local disk=$(df -h / | awk 'NR==2{print $5}')
    local cpu_idle=$(top -bn1 | grep "Cpu(s)" | awk '{print $8}' | cut -d'%' -f1)
    local cpu_used=$(echo "100 - $cpu_idle" | bc 2>/dev/null || echo "0")
    
    echo "System: Load=$load | CPU=${cpu_used}% | Memory=$mem | Disk=$disk"
    
    local cpu_int=$(printf "%.0f" "$cpu_used" 2>/dev/null || echo "0")
    if [ "$cpu_int" -ge 90 ] 2>/dev/null; then
        log_warn "High CPU usage: ${cpu_used}%"
    fi
    
    if [ "${mem_avail:-0}" -lt 500 ] 2>/dev/null; then
        log_warn "Low available memory: ${mem_avail}MB"
    fi
    
    local disk_pct=$(echo "$disk" | tr -d '%')
    if [ "${disk_pct:-0}" -ge 90 ] 2>/dev/null; then
        log_warn "High disk usage: $disk"
    fi
}

################################################################################
# SNAPSHOT GENERATOR
################################################################################

generate_snapshot() {
    local timestamp=$(get_timestamp)
    
    cat > "$LATEST_FILE" << EOF
════════════════════════════════════════════════════════════════════════════════
CHATBERRY SERVER OBSERVER - LATEST SNAPSHOT
════════════════════════════════════════════════════════════════════════════════
Timestamp:  $timestamp
Host:       $HOSTNAME
PID:        $SCRIPT_PID
Interval:   ${CHECK_INTERVAL}s
════════════════════════════════════════════════════════════════════════════════

SYSTEM METRICS:
$(get_system_info)

REDIS:
$(get_redis_info)

MYSQL:
$(get_mysql_info)

PHP-FPM:
$(get_fpm_info)

SUPERVISOR:
$(get_supervisor_info)

QUEUES:
$(check_queues)

════════════════════════════════════════════════════════════════════════════════
View detailed queue table: cat $QUEUES_LOG
View alerts only:          cat $ALERTS_LOG
View all metrics:          tail -f $METRICS_LOG
════════════════════════════════════════════════════════════════════════════════
EOF
    
    chmod 644 "$LATEST_FILE"
}

################################################################################
# CLEANUP
################################################################################

cleanup() {
    echo ""
    log_info "Observer stopped by user (PID: $SCRIPT_PID)"
    echo ""
    exit 0
}

trap cleanup SIGINT SIGTERM

################################################################################
# MAIN LOOP
################################################################################

setup_log_directory
print_banner

discover_queues

rediscovery_counter=0

log_info "Starting monitoring loop..."
echo ""

while true; do
    print_color blue "════════════════════════════════════════════════════════════════"
    print_color cyan "  Check at $(get_timestamp)"
    print_color blue "════════════════════════════════════════════════════════════════"
    
    get_system_info
    get_redis_info
    get_mysql_info
    get_fpm_info
    get_supervisor_info
    check_queues
    
    generate_snapshot
    
    {
        echo "════════════════════════════════════════════════════════════════"
        echo "Metrics collected at $(get_timestamp)"
        echo "════════════════════════════════════════════════════════════════"
        get_system_info
        get_redis_info
        get_mysql_info
        get_fpm_info
        get_supervisor_info
        echo ""
    } >> "$METRICS_LOG"
    
    echo ""
    print_color green "✓ Snapshot saved to: $LATEST_FILE"
    echo ""
    
    rediscovery_counter=$((rediscovery_counter + CHECK_INTERVAL))
    if [ $rediscovery_counter -ge $REDISCOVERY_INTERVAL ]; then
        discover_queues
        rediscovery_counter=0
    fi
    
    print_color yellow "⏳ Waiting ${CHECK_INTERVAL}s until next check..."
    echo ""
    sleep "$CHECK_INTERVAL"
done
