#!/usr/bin/env bash

set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repository_root"

runway_key_files="$(git grep -l -I -E 'key_[[:alnum:]_-]{64,}' -- . || true)"

if [[ -n "$runway_key_files" ]]; then
  echo "Runway API credential pattern detected in tracked files:" >&2
  printf '%s\n' "$runway_key_files" >&2
  echo "Remove the credential and configure RUNWAY_API_KEY outside Git." >&2
  exit 1
fi

collection="postman/runway-foto-a-video-loop.postman_collection.json"

if [[ -f "$collection" ]] && jq -e '
  any(
    .variable[]?;
    .key == "RUNWAY_API_KEY" and ((.value // "") | length) > 0
  )
' "$collection" >/dev/null; then
  echo "RUNWAY_API_KEY must remain empty in the tracked Postman collection." >&2
  exit 1
fi

echo "No tracked Runway API credentials detected."
