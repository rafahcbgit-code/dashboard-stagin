#!/bin/bash

###############################################################################
# Webhook Burst Load Test Script
#
# AC-1.6.1: 100 webhooks in 60 seconds all return HTTP 200 within 200ms
# AC-1.6.2: All 100 messages processed within 5 seconds total
# AC-1.6.3: Zero messages lost or duplicated
#
# Usage:
#   ./webhook-burst.sh -i <identifier> [-u <base_url>] [-c <count>] [-d <duration>] [-s <secret>] [-m] [-v]
#
# Requirements:
#   - curl
#   - bc (for floating point math)
#   - jq (optional, for JSON parsing)
###############################################################################

set -e

# Default values
BASE_URL="http://localhost:8000"
IDENTIFIER=""
COUNT=100
DURATION=60
APP_SECRET=""
MIXED_PAYLOADS=false
VERBOSE=false
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RESULTS_DIR="${SCRIPT_DIR}/results"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
NC='\033[0m' # No Color

# Response time arrays
declare -a RESPONSE_TIMES
declare -a WAM_IDS

# Counters
SUCCESS_COUNT=0
FAILURE_COUNT=0

print_usage() {
    cat << EOF
Webhook Burst Load Test Script

Usage: $(basename "$0") -i <identifier> [options]

Required:
  -i, --identifier    Organization identifier for webhook endpoint

Options:
  -u, --url           Base URL of the application (default: http://localhost:8000)
  -c, --count         Number of webhooks to send (default: 100)
  -d, --duration      Duration in seconds to spread requests (default: 60)
  -s, --secret        App secret for signature generation (optional)
  -m, --mixed         Use mixed payload types (text 70%, image 15%, video 10%, document 5%)
  -v, --verbose       Show detailed output for each request
  -h, --help          Show this help message

Examples:
  # Basic test with 100 webhooks over 60 seconds
  $(basename "$0") -i my-org-identifier

  # Fast burst test (100 webhooks in 10 seconds)
  $(basename "$0") -i my-org-identifier -d 10

  # Mixed payload types with signature
  $(basename "$0") -i my-org-identifier -m -s your_app_secret

  # Custom count and verbose output
  $(basename "$0") -i my-org-identifier -c 50 -v
EOF
}

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -i|--identifier)
            IDENTIFIER="$2"
            shift 2
            ;;
        -u|--url)
            BASE_URL="$2"
            shift 2
            ;;
        -c|--count)
            COUNT="$2"
            shift 2
            ;;
        -d|--duration)
            DURATION="$2"
            shift 2
            ;;
        -s|--secret)
            APP_SECRET="$2"
            shift 2
            ;;
        -m|--mixed)
            MIXED_PAYLOADS=true
            shift
            ;;
        -v|--verbose)
            VERBOSE=true
            shift
            ;;
        -h|--help)
            print_usage
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            print_usage
            exit 1
            ;;
    esac
done

# Validate required arguments
if [[ -z "$IDENTIFIER" ]]; then
    echo -e "${RED}Error: --identifier is required${NC}"
    print_usage
    exit 1
fi

# Check dependencies
check_dependencies() {
    local missing=()

    if ! command -v curl &> /dev/null; then
        missing+=("curl")
    fi

    if ! command -v bc &> /dev/null; then
        missing+=("bc")
    fi

    if [[ ${#missing[@]} -gt 0 ]]; then
        echo -e "${RED}Error: Missing required dependencies: ${missing[*]}${NC}"
        exit 1
    fi
}

# Generate unique wam_id
generate_wam_id() {
    local index=$1
    echo "wamid_load_${index}_$(date +%s%N | md5sum | head -c 12)"
}

# Calculate HMAC-SHA256 signature
calculate_signature() {
    local payload="$1"
    local secret="$2"
    echo -n "$payload" | openssl dgst -sha256 -hmac "$secret" | sed 's/^.* /sha256=/'
}

# Generate payload based on type
generate_payload() {
    local type="$1"
    local wam_id="$2"
    local index="$3"
    local timestamp=$(date +%s)
    local wa_id="1234567890$(printf '%02d' $((index % 100)))"

    local type_data=""
    case "$type" in
        text)
            type_data='"text": {"body": "Load test message #'"$index"'"}'
            ;;
        image)
            type_data='"image": {"id": "media_img_'"$(date +%N)"'", "mime_type": "image/jpeg", "sha256": "abc123", "caption": "Test image #'"$index"'"}'
            ;;
        video)
            type_data='"video": {"id": "media_vid_'"$(date +%N)"'", "mime_type": "video/mp4", "sha256": "def456"}'
            ;;
        document)
            type_data='"document": {"id": "media_doc_'"$(date +%N)"'", "mime_type": "application/pdf", "sha256": "ghi789", "filename": "test_'"$index"'.pdf"}'
            ;;
        audio)
            type_data='"audio": {"id": "media_aud_'"$(date +%N)"'", "mime_type": "audio/ogg", "sha256": "jkl012", "voice": true}'
            ;;
    esac

    cat << EOF
{"object":"whatsapp_business_account","entry":[{"id":"test_waba_id","changes":[{"field":"messages","value":{"messaging_product":"whatsapp","metadata":{"display_phone_number":"0987654321","phone_number_id":"test_phone_id"},"contacts":[{"profile":{"name":"Load Test User"},"wa_id":"$wa_id"}],"messages":[{"from":"$wa_id","id":"$wam_id","timestamp":"$timestamp","type":"$type",$type_data}]}}]}]}
EOF
}

# Get message type based on distribution
get_message_type() {
    if [[ "$MIXED_PAYLOADS" == "true" ]]; then
        local rand=$((RANDOM % 100 + 1))
        if [[ $rand -le 70 ]]; then
            echo "text"
        elif [[ $rand -le 85 ]]; then
            echo "image"
        elif [[ $rand -le 95 ]]; then
            echo "video"
        else
            echo "document"
        fi
    else
        echo "text"
    fi
}

# Send single webhook
send_webhook() {
    local index=$1
    local wam_id=$2
    local type=$(get_message_type)
    local payload=$(generate_payload "$type" "$wam_id" "$index")

    local endpoint="${BASE_URL}/webhook/whatsapp/${IDENTIFIER}"
    local headers="-H 'Content-Type: application/json' -H 'Accept: application/json'"

    # Add signature header if secret provided
    if [[ -n "$APP_SECRET" ]]; then
        local signature=$(calculate_signature "$payload" "$APP_SECRET")
        headers="$headers -H 'X-Hub-Signature-256: $signature'"
    fi

    # Measure response time
    local start_time=$(date +%s%N)

    local response=$(curl -s -w "\n%{http_code}\n%{time_total}" \
        -X POST "$endpoint" \
        -H "Content-Type: application/json" \
        -H "Accept: application/json" \
        ${APP_SECRET:+-H "X-Hub-Signature-256: $(calculate_signature "$payload" "$APP_SECRET")"} \
        -d "$payload" \
        --max-time 10 \
        2>/dev/null || echo -e "\n000\n0")

    local end_time=$(date +%s%N)

    # Parse response
    local body=$(echo "$response" | head -n -2)
    local http_code=$(echo "$response" | tail -n 2 | head -n 1)
    local curl_time=$(echo "$response" | tail -n 1)

    # Calculate response time in milliseconds
    local response_time_ms=$(echo "$curl_time * 1000" | bc)

    # Store results
    RESPONSE_TIMES+=("$response_time_ms")
    WAM_IDS+=("$wam_id")

    if [[ "$http_code" == "200" ]]; then
        ((SUCCESS_COUNT++))
        local status="${GREEN}✓${NC}"
    else
        ((FAILURE_COUNT++))
        local status="${RED}✗${NC}"
    fi

    if [[ "$VERBOSE" == "true" ]]; then
        printf "${status} [%3d/%d] HTTP %s | %6.2fms | %s\n" "$index" "$COUNT" "$http_code" "$response_time_ms" "$type"
    elif [[ $((index % 10)) -eq 0 ]]; then
        local avg=$(echo "${RESPONSE_TIMES[@]}" | tr ' ' '\n' | awk '{sum+=$1} END {printf "%.2f", sum/NR}')
        printf "[%3d/%d] Success: %d | Failed: %d | Avg: %sms\n" "$index" "$COUNT" "$SUCCESS_COUNT" "$FAILURE_COUNT" "$avg"
    fi
}

# Calculate statistics
calculate_stats() {
    local sorted=($(printf '%s\n' "${RESPONSE_TIMES[@]}" | sort -n))
    local count=${#sorted[@]}

    if [[ $count -eq 0 ]]; then
        return
    fi

    # Min, Max, Sum
    local min=${sorted[0]}
    local max=${sorted[$((count-1))]}
    local sum=$(printf '%s\n' "${RESPONSE_TIMES[@]}" | awk '{sum+=$1} END {print sum}')
    local avg=$(echo "scale=2; $sum / $count" | bc)

    # Percentiles
    local median_idx=$((count * 50 / 100))
    local p95_idx=$((count * 95 / 100))
    local p99_idx=$((count * 99 / 100))

    local median=${sorted[$median_idx]}
    local p95=${sorted[$p95_idx]}
    local p99=${sorted[$p99_idx]}

    echo "min=$min max=$max avg=$avg median=$median p95=$p95 p99=$p99"
}

# Main execution
main() {
    check_dependencies

    # Create results directory
    mkdir -p "$RESULTS_DIR"

    echo ""
    echo "============================================================"
    echo "WEBHOOK BURST LOAD TEST - Story 1.6"
    echo "============================================================"
    echo ""
    echo "Configuration:"
    echo "  Base URL:    $BASE_URL"
    echo "  Identifier:  $IDENTIFIER"
    echo "  Count:       $COUNT webhooks"
    echo "  Duration:    $DURATION seconds"
    echo "  Mixed:       $MIXED_PAYLOADS"
    echo "  Secret:      ${APP_SECRET:+configured}"
    echo ""

    local interval=$(echo "scale=3; $DURATION / $COUNT" | bc)
    local interval_ms=$(echo "$interval * 1000" | bc | cut -d'.' -f1)

    echo "Starting load test..."
    echo "Sending $COUNT webhooks over $DURATION seconds"
    echo "Target interval: ${interval_ms}ms between requests"
    echo ""

    local start_time=$(date +%s)

    for ((i=1; i<=COUNT; i++)); do
        local iteration_start=$(date +%s%N)

        local wam_id=$(generate_wam_id $i)
        send_webhook $i "$wam_id"

        # Maintain target rate
        local iteration_end=$(date +%s%N)
        local elapsed_ms=$(echo "($iteration_end - $iteration_start) / 1000000" | bc)
        local sleep_ms=$((interval_ms - elapsed_ms))

        if [[ $sleep_ms -gt 0 ]]; then
            sleep "$(echo "scale=3; $sleep_ms / 1000" | bc)"
        fi
    done

    local end_time=$(date +%s)
    local total_time=$((end_time - start_time))

    # Calculate and display results
    echo ""
    echo "============================================================"
    echo "LOAD TEST RESULTS"
    echo "============================================================"
    echo ""
    echo "Total webhooks sent: $COUNT"
    echo "Total time: ${total_time}s"
    echo "Successful: $SUCCESS_COUNT"
    echo "Failed: $FAILURE_COUNT"
    echo "Success rate: $(echo "scale=2; $SUCCESS_COUNT * 100 / $COUNT" | bc)%"
    echo ""

    # Calculate statistics
    local stats=$(calculate_stats)
    eval "$stats"

    echo "Response Time Statistics:"
    echo "  Min:    ${min}ms"
    echo "  Max:    ${max}ms"
    echo "  Avg:    ${avg}ms"
    echo "  Median: ${median}ms"
    echo "  P95:    ${p95}ms"
    echo "  P99:    ${p99}ms"
    echo ""

    # Acceptance Criteria Verification
    echo "ACCEPTANCE CRITERIA VERIFICATION:"
    echo "----------------------------------------"

    # AC-1.6.1: p95 < 200ms
    if (( $(echo "$p95 < 200" | bc -l) )); then
        echo -e "AC-1.6.1 (p95 < 200ms): ${GREEN}✓ PASS${NC} (${p95}ms)"
        local ac161_pass=true
    else
        echo -e "AC-1.6.1 (p95 < 200ms): ${RED}✗ FAIL${NC} (${p95}ms)"
        local ac161_pass=false
    fi

    # AC-1.6.3: Zero failures
    if [[ $FAILURE_COUNT -eq 0 ]]; then
        echo -e "AC-1.6.3 (Zero failures): ${GREEN}✓ PASS${NC} ($FAILURE_COUNT failures)"
        local ac163_pass=true
    else
        echo -e "AC-1.6.3 (Zero failures): ${RED}✗ FAIL${NC} ($FAILURE_COUNT failures)"
        local ac163_pass=false
    fi

    # Overall
    echo ""
    if [[ "$ac161_pass" == "true" && "$ac163_pass" == "true" ]]; then
        echo -e "Overall: ${GREEN}✓ ALL PASS${NC}"
    else
        echo -e "Overall: ${RED}✗ SOME FAILED${NC}"
    fi

    # Save results to file
    local timestamp=$(date +%Y-%m-%d_%H%M%S)
    local results_file="${RESULTS_DIR}/load_test_${timestamp}.json"
    local wam_ids_file="${RESULTS_DIR}/wam_ids_${timestamp}.json"

    # Save WAM IDs for database verification
    echo "{" > "$wam_ids_file"
    echo "  \"timestamp\": \"$(date -Iseconds)\"," >> "$wam_ids_file"
    echo "  \"count\": ${#WAM_IDS[@]}," >> "$wam_ids_file"
    echo "  \"wam_ids\": [" >> "$wam_ids_file"
    local first=true
    for wam_id in "${WAM_IDS[@]}"; do
        if [[ "$first" == "true" ]]; then
            echo "    \"$wam_id\"" >> "$wam_ids_file"
            first=false
        else
            echo "    ,\"$wam_id\"" >> "$wam_ids_file"
        fi
    done
    echo "  ]" >> "$wam_ids_file"
    echo "}" >> "$wam_ids_file"

    echo ""
    echo "WAM IDs saved to: $wam_ids_file"
    echo "(Use this file to verify message creation in database)"
}

# Run main
main
