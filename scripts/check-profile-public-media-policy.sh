#!/usr/bin/env bash

set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
template="${1:-$repository_root/infra/cloudformation/bigmelo-prod.yml}"

if [[ ! -f "$template" ]]; then
  echo "CloudFormation template not found: $template" >&2
  exit 1
fi

policy_block="$(sed -n '/^  ProfilesBucketPolicy:/,/^  ApiRepository:/p' "$template")"
runtime_policy_block="$(sed -n '/^  ApiInstanceRole:/,/^  ApiInstanceProfile:/p' "$template")"
normalized_runtime_policy="$(sed -E 's/^[[:space:]]*-[[:space:]]*//' <<<"$runtime_policy_block")"

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

required_runtime_actions=(
  's3:GetObject'
  's3:PutObject'
  's3:PutObjectAcl'
  's3:DeleteObject'
  's3:AbortMultipartUpload'
  's3:ListBucket'
)

for action in "${required_runtime_actions[@]}"; do
  if ! grep -Fxq -- "$action" <<<"$normalized_runtime_policy"; then
    echo "ApiInstanceRole is missing required profile storage action: $action" >&2
    exit 1
  fi
done

required_runtime_resources=(
  '!Sub arn:${AWS::Partition}:s3:::bigmelo-${Environment}-profiles-${AWS::AccountId}/*'
  '!Sub arn:${AWS::Partition}:s3:::bigmelo-${Environment}-profiles-${AWS::AccountId}'
)

for resource in "${required_runtime_resources[@]}"; do
  if ! grep -Fxq -- "$resource" <<<"$normalized_runtime_policy"; then
    echo "ApiInstanceRole is missing required profile storage resource: $resource" >&2
    exit 1
  fi
done

echo "Public profile media prefixes and API runtime storage permissions are explicitly covered; private source prefixes remain excluded from anonymous access."
