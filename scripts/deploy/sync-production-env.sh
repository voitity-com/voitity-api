#!/usr/bin/env bash

set -euo pipefail

target_directory="${1:?Target directory is required}"
profile_domain_distribution_id="${2:?Profile domain distribution ID is required}"
profile_domain_connection_group_id="${3:?Profile domain connection group ID is required}"
profile_domain_routing_endpoint="${4:?Profile domain routing endpoint is required}"

asm_exec_commit="1799aae50f30a8e97bab4faf38f2eadbd665028b"
asm_exec_checksum="d55eb38ad33a5b76f584ca180f633ecc120cf39b8fd29427ffbe11a8fbf19556"
asm_exec_url="https://raw.githubusercontent.com/aws/agent-toolkit-for-aws/${asm_exec_commit}/plugins/aws-core/skills/aws-secrets-manager/references/asm-exec"

if [[ ! "$profile_domain_distribution_id" =~ ^E[A-Z0-9]+$ ]]; then
    echo "Invalid profile domain distribution ID." >&2
    exit 1
fi

if [[ ! "$profile_domain_connection_group_id" =~ ^cg_[A-Za-z0-9]+$ ]]; then
    echo "Invalid profile domain connection group ID." >&2
    exit 1
fi

if [[ ! "$profile_domain_routing_endpoint" =~ ^[A-Za-z0-9.-]+$ ]]; then
    echo "Invalid profile domain routing endpoint." >&2
    exit 1
fi

test -d "$target_directory"

asm_exec_path="$(mktemp)"
environment_path="$(mktemp "${target_directory}/.env.next.XXXXXX")"

cleanup() {
    rm -f "$asm_exec_path" "$environment_path"
}

trap cleanup EXIT

curl --fail --silent --show-error --location "$asm_exec_url" --output "$asm_exec_path"
printf '%s  %s\n' "$asm_exec_checksum" "$asm_exec_path" | sha256sum --check --status
chmod 700 "$asm_exec_path"

export AWS_REGION="us-east-1"
export BIGMELO_PRODUCTION_ENV='{{resolve:secretsmanager:bigmelo/prod/api/env:SecretString::AWSCURRENT}}'
export BIGMELO_ENVIRONMENT_PATH="$environment_path"
export BIGMELO_PROFILE_DOMAIN_DISTRIBUTION_ID="$profile_domain_distribution_id"
export BIGMELO_PROFILE_DOMAIN_CONNECTION_GROUP_ID="$profile_domain_connection_group_id"
export BIGMELO_PROFILE_DOMAIN_ROUTING_ENDPOINT="$profile_domain_routing_endpoint"

"$asm_exec_path" -- python3 -c '
import os
import re
from pathlib import Path

content = os.environ["BIGMELO_PRODUCTION_ENV"]
target = Path(os.environ["BIGMELO_ENVIRONMENT_PATH"])

values = {}
for raw_line in content.splitlines():
    line = raw_line.strip()
    if not line or line.startswith("#") or "=" not in line:
        continue
    key, value = line.split("=", 1)
    values[key.strip()] = value.strip()

required = (
    "APP_ENV",
    "APP_KEY",
    "APP_URL",
    "DB_CONNECTION",
    "DB_HOST",
    "DB_DATABASE",
    "DB_USERNAME",
    "DB_PASSWORD",
    "RUNWAY_API_KEY",
)
missing = [key for key in required if not values.get(key)]
if missing:
    raise SystemExit("Production environment is missing required keys: " + ", ".join(missing))

if values["APP_ENV"].strip("\"\x27") != "production":
    raise SystemExit("APP_ENV must be production.")

runway_key = values["RUNWAY_API_KEY"].strip("\"\x27")
if not re.fullmatch(r"key_[A-Za-z0-9_-]{64,}", runway_key):
    raise SystemExit("RUNWAY_API_KEY has an invalid format.")

lines = [
    raw_line
    for raw_line in content.splitlines()
    if not raw_line.startswith("PROFILE_DOMAIN_")
]
lines.extend(
    [
        "PROFILE_DOMAIN_DRIVER=cloudfront",
        "PROFILE_DOMAIN_AWS_REGION=us-east-1",
        "PROFILE_DOMAIN_CLOUDFRONT_DISTRIBUTION_ID="
        + os.environ["BIGMELO_PROFILE_DOMAIN_DISTRIBUTION_ID"],
        "PROFILE_DOMAIN_CLOUDFRONT_CONNECTION_GROUP_ID="
        + os.environ["BIGMELO_PROFILE_DOMAIN_CONNECTION_GROUP_ID"],
        "PROFILE_DOMAIN_CLOUDFRONT_ROUTING_ENDPOINT="
        + os.environ["BIGMELO_PROFILE_DOMAIN_ROUTING_ENDPOINT"],
        "PROFILE_DOMAIN_VALIDATION_TOKEN_HOST=cloudfront",
    ]
)

target.write_text("\n".join(lines).rstrip("\n") + "\n", encoding="utf-8")
os.chmod(target, 0o600)
'

unset BIGMELO_PRODUCTION_ENV

test -s "$environment_path"
chown root:root "$environment_path"
chmod 600 "$environment_path"
mv -f "$environment_path" "${target_directory}/.env"

echo "Production environment synchronized from Secrets Manager."
