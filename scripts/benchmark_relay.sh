#!/usr/bin/env bash
set -euo pipefail

# Simple baseline benchmark using curl timing output.
# Usage:
#   ./scripts/benchmark_relay.sh --direct https://origin.example.com/health --relay https://relay.example.com/health --requests 30

DIRECT_URL=""
RELAY_URL=""
REQUESTS=30
TIMEOUT=8

while [[ $# -gt 0 ]]; do
  case "$1" in
    --direct) DIRECT_URL="$2"; shift 2 ;;
    --relay) RELAY_URL="$2"; shift 2 ;;
    --requests) REQUESTS="$2"; shift 2 ;;
    --timeout) TIMEOUT="$2"; shift 2 ;;
    *) echo "Unknown arg: $1" >&2; exit 1 ;;
  esac
done

if [[ -z "$DIRECT_URL" || -z "$RELAY_URL" ]]; then
  echo "direct/relay url required" >&2
  exit 1
fi

run_case() {
  local name="$1"
  local url="$2"
  local out="/tmp/${name}_times.txt"
  : > "$out"
  local ok=0
  local fail=0
  for _ in $(seq 1 "$REQUESTS"); do
    t=$(curl -sS -o /dev/null -w '%{time_total}' --max-time "$TIMEOUT" "$url" || true)
    if [[ -n "$t" && "$t" != "0.000000" ]]; then
      echo "$t" >> "$out"
      ok=$((ok+1))
    else
      fail=$((fail+1))
    fi
  done
  avg=$(awk '{s+=$1} END {if (NR==0) print 0; else printf "%.4f", s/NR}' "$out")
  p95=$(sort -n "$out" | awk 'BEGIN{p=0.95} {a[NR]=$1} END{if(NR==0){print 0}else{i=int(p*NR); if(i<1)i=1; printf "%.4f", a[i]}}')
  echo "$name ok=$ok fail=$fail avg=${avg}s p95=${p95}s"
}

echo "Benchmark requests=$REQUESTS timeout=${TIMEOUT}s"
run_case direct "$DIRECT_URL"
run_case relay "$RELAY_URL"
