#!/usr/bin/env bash

set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
template="${1:-$repository_root/infra/cloudformation/bigmelo-prod.yml}"

if [[ ! -f "$template" ]]; then
  echo "CloudFormation template not found: $template" >&2
  exit 1
fi

policy_block="$(sed -n '/^  ProfilesBucketPolicy:/,/^  ApiRepository:/p' "$template")"

required_public_prefixes=(
  '/images/*'
  '/videos/*'
  '/audio/*'
  '/profiles/*/backgrounds/*'
  '/products/*'
  '/integrations/onlyfans/*'
  '/integrations/other/*'
)

for prefix in "${required_public_prefixes[@]}"; do
  if ! grep -Fq -- "$prefix" <<<"$policy_block"; then
    echo "ProfilesBucketPolicy is missing required public prefix: $prefix" >&2
    exit 1
  fi
done

for private_prefix in '/sources/*' '/businesses/*'; do
  if grep -Fq -- "$private_prefix" <<<"$policy_block"; then
    echo "ProfilesBucketPolicy must not expose private prefix: $private_prefix" >&2
    exit 1
  fi
done

echo "Public profile media prefixes are explicitly covered; private source prefixes remain excluded."
