#!/usr/bin/env bash
# End-to-end check of the whole stack through the gateway, in its own throwaway Compose project
# (own volumes, subnet, ports and random secrets): it never touches the development stack or its Redis stream.
#
# Needs: docker compose, curl, python3, and `composer install` already done in every services/* directory.
# Usage: bash e2e/run.sh      Debug a failure: KEEP=1 bash e2e/run.sh (leaves the project up; remove it with
#        docker compose -p mestaro-e2e down -v)
set -euo pipefail
cd "$(dirname "$0")/.."

SERVICES=(platform catalog operations billing)
PORT=8199
G="http://localhost:${PORT}"
ENVFILE="$(mktemp)"
FAILED=0

dc() { docker compose --env-file "$ENVFILE" -p mestaro-e2e "$@"; }
step() { printf '\n== %s\n' "$1"; }
ok() { printf '  ok   %s\n' "$1"; }
bad() { printf '  FAIL %s\n' "$1"; FAILED=1; }
check() { local what="$1"; shift; if "$@" >/dev/null 2>&1; then ok "$what"; else bad "$what"; fi; }
secret() { openssl rand -hex 24; }
json() { python3 -c "import sys,json; d=json.load(sys.stdin); print($1)"; }

cleanup() {
    local code=$?
    if [ "$code" -ne 0 ] || [ "$FAILED" -ne 0 ]; then
        printf '\n-- container state and recent logs\n'
        dc ps --format '{{.Service}} {{.Status}}' 2>&1 | sort || true
        # Health-check hits on the web processes are noise; everything else from the event pipeline is useful.
        dc logs --no-color --tail 30 platform-relay catalog-relay operations-relay billing-relay \
            platform-consumer catalog-consumer operations-consumer billing-consumer 2>&1 | grep -v 'GET /index.php' | tail -150 || true
    fi
    if [ "${KEEP:-0}" = 1 ]; then
        echo "KEEP=1: project mestaro-e2e left running (gateway on :${PORT})."
    else
        dc down -v --remove-orphans >/dev/null 2>&1 || true
    fi
    rm -f "$ENVFILE"
    exit $((code != 0 || FAILED != 0))
}
trap cleanup EXIT

step "preconditions"
for s in "${SERVICES[@]}"; do
    [ -f "services/$s/vendor/autoload.php" ] || { echo "services/$s/vendor is missing: run composer install there first."; exit 2; }
done
command -v python3 >/dev/null && command -v curl >/dev/null || { echo "curl and python3 are required."; exit 2; }

step "build the image and generate throwaway secrets"
cat > "$ENVFILE" <<EOF
HOST_UID=$(id -u)
HOST_GID=$(id -g)
GATEWAY_PORT=${PORT}
MAILPIT_PORT=8198
INTERNAL_SUBNET=172.31.251.0/24
POSTGRES_PASSWORD=$(secret)
REDIS_PASSWORD=$(secret)
TENANCY_SERVICES=platform,catalog,operations,billing
PLATFORM_JWT_PRIVATE_KEY=replaced-after-the-image-exists
EOF
for s in "${SERVICES[@]}"; do
    U=$(echo "$s" | tr '[:lower:]' '[:upper:]')
    printf '%s_DB_PASSWORD=%s\n%s_APP_KEY=base64:%s\n' "$U" "$(secret)" "$U" "$(openssl rand -base64 32)" >> "$ENVFILE"
done
# Build only when the image is missing (CI) or E2E_BUILD=1: it needs internet for apt and PECL, which a local
# development image already has.
if [ "${E2E_BUILD:-0}" = 1 ] || ! docker image inspect mestaro-php-dev:latest >/dev/null 2>&1; then
    dc build platform >/dev/null
fi
# The signing key is made by the image itself (sodium), so the format is exactly what platform expects.
JWT_KEY=$(docker run --rm --entrypoint php mestaro-php-dev:latest -r 'echo rtrim(strtr(base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())), "+/", "-_"), "=");')
sed -i "s|^PLATFORM_JWT_PRIVATE_KEY=.*|PLATFORM_JWT_PRIVATE_KEY=${JWT_KEY}|" "$ENVFILE"
ok "image built, secrets generated"

step "start the stack"
dc up -d >/dev/null
# Relays and consumers may restart a few times until the migrations of their service have run: only the
# four web processes decide readiness.
healthy() { dc ps --format '{{.Service}} {{.Health}}' | grep -cE '^(platform|catalog|operations|billing) healthy$' || true; }
for _ in $(seq 1 120); do [ "$(healthy)" = 4 ] && break; sleep 2; done
check "all four services healthy" test "$(healthy)" = 4

step "administrator login"
PW="e2e-$(secret)"
printf '%s\n' "$PW" | dc exec -T platform php artisan platform:create-admin e2e@example.test "E2E Admin" >/dev/null
TOKEN=$(curl -fsS -X POST "$G/api/v1/auth/login" -H 'Content-Type: application/json' -d "{\"email\":\"e2e@example.test\",\"password\":\"$PW\"}" | json 'd["data"]["access_token"]')
check "login returns an access token" test -n "$TOKEN"
check "a wrong password is rejected" test "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$G/api/v1/auth/login" -H 'Content-Type: application/json' -d '{"email":"e2e@example.test","password":"wrong-password-123"}')" = 401
check "admin API without a token is rejected" test "$(curl -s -o /dev/null -w '%{http_code}' "$G/api/v1/admin/restaurants/01JABCDEFGHJKMNPQRSTVWXYZ0")" = 401

register() { curl -fsS -X POST "$G/api/v1/admin/restaurants" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d "{\"name\":\"$1\",\"slug\":\"$2\"}" | json 'd["data"]["id"]'; }
status_of() { curl -fsS "$G/api/v1/admin/restaurants/$1" -H "Authorization: Bearer $TOKEN" | json 'd["data"]["status"]+"|"+str(d["data"].get("failure_reason"))'; }
wait_for() { # id, expected status prefix
    local s=""
    for _ in $(seq 1 60); do
        s=$(status_of "$1")
        case "$s" in "$2"*) echo "$s"; return 0 ;; esac
        sleep 1
    done
    echo "$s"; return 1
}
tenant_dbs() { dc exec -T postgres psql -U postgres -tAc "select datname from pg_database where datname like 'tenant_$(echo "$1" | tr '[:upper:]' '[:lower:]')_%' order by 1" | tr '\n' ' '; }

step "provisioning: every service answers and the restaurant activates"
ID1=$(register "E2E One" e2eone)
check "registration is accepted as provisioning" test -n "$ID1"
RES=$(wait_for "$ID1" active) && ok "restaurant became active ($RES)" || bad "restaurant did not become active (last: $RES)"
L1=$(echo "$ID1" | tr '[:upper:]' '[:lower:]')
EXPECTED="tenant_${L1}_billing tenant_${L1}_catalog tenant_${L1}_operations tenant_${L1}_platform "
check "one database per service exists for the tenant" test "$(tenant_dbs "$ID1")" = "$EXPECTED"
check "a duplicated slug is refused" test "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$G/api/v1/admin/restaurants" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"name":"Again","slug":"e2eone"}')" = 409

step "provisioning failure in one service, then a successful retry"
dc exec -T postgres psql -U postgres -qc "ALTER ROLE billing NOCREATEDB" >/dev/null
ID2=$(register "E2E Two" e2etwo)
RES=$(wait_for "$ID2" provisioning_failed) && ok "restaurant is provisioning_failed ($RES)" || bad "restaurant did not fail as expected (last: $RES)"
check "the reason names the service and is generic" test "$RES" = "provisioning_failed|billing: database creation failed"
dc exec -T postgres psql -U postgres -qc "ALTER ROLE billing CREATEDB" >/dev/null
check "retry is accepted" test "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$G/api/v1/admin/restaurants/$ID2/retry-provisioning" -H "Authorization: Bearer $TOKEN")" = 202
RES=$(wait_for "$ID2" active) && ok "retry converged to active ($RES)" || bad "retry did not converge (last: $RES)"

step "gateway routing by prefix"
for p in zones orders payments kds webhooks; do
    code=$(curl -s "$G/api/v1/$p" | json 'd["error"]["code"]' 2>/dev/null || echo "not-a-service-answer")
    check "/$p reaches its service" test "$code" = NOT_FOUND
done
UNKNOWN=$(curl -s -w '\n%{http_code}' "$G/api/v1/nonexistent")
check "an unknown prefix is answered by the gateway, not a service" test "$(echo "$UNKNOWN" | tail -1)" = 404 -a "$(echo "$UNKNOWN" | grep -c '"error"')" = 0
check "security headers are present" test -n "$(curl -sI "$G/api/v1/zones" | grep -i '^x-content-type-options: nosniff')"
check "the JWKS endpoint serves a key" test "$(curl -fsS "$G/.well-known/jwks.json" | json 'len(d["keys"])')" = 1

if [ "$FAILED" -eq 0 ]; then printf '\nE2E PASSED\n'; else printf '\nE2E FAILED\n'; fi
