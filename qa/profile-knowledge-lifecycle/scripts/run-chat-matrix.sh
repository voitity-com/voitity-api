#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SUITE_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
QUESTIONS_FILE="${QA_QUESTIONS_FILE:-${SUITE_ROOT}/manifests/questions.json}"
PROFILE_ID="${1:-${QA_PROFILE_ID:-}}"
OUTPUT_FILE="${2:-${QA_OUTPUT_FILE:-}}"
API_BASE_URL="${QA_API_BASE_URL:-http://localhost:8000}"
DELAY_SECONDS="${QA_CHAT_DELAY_SECONDS:-3.2}"
RUN_ID="${QA_RUN_ID:-$(date -u +%Y%m%dT%H%M%SZ)}"

if [[ -z "${PROFILE_ID}" ]]; then
  echo "Usage: $0 <profile-id> [output-file]" >&2
  exit 2
fi

if [[ -z "${OUTPUT_FILE}" ]]; then
  OUTPUT_FILE="${SUITE_ROOT}/outputs/${RUN_ID}/results.json"
fi

mkdir -p "$(dirname "${OUTPUT_FILE}")"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT
RESULTS_JSONL="${TMP_DIR}/results.jsonl"
STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
TOTAL="$(jq '[.groups[].questions[]] | length' "${QUESTIONS_FILE}")"
CURRENT=0

while IFS= read -r item; do
  CURRENT=$((CURRENT + 1))
  ID="$(jq -r '.id' <<<"${item}")"
  QUESTION="$(jq -r '.question' <<<"${item}")"
  AREA="$(jq -r '.area' <<<"${item}")"
  KEY="$(jq -r '.key' <<<"${item}")"
  EXPECTED_UI="$(jq -r '.expected_ui // ""' <<<"${item}")"
  MUST_INCLUDE="$(jq -c '.must_include // []' <<<"${item}")"
  REQUIRE_ATTACHMENT="$(jq -r 'if .require_attachment == null then "auto" else (.require_attachment | tostring) end' <<<"${item}")"
  VISITOR_ID="qa-${RUN_ID}-${ID}-$(uuidgen | tr '[:upper:]' '[:lower:]')"
  RESPONSE_FILE="${TMP_DIR}/${CURRENT}.json"
  HTTP_CODE=0

  for attempt in 1 2 3; do
    HTTP_CODE="$(curl --silent --show-error \
      --output "${RESPONSE_FILE}" \
      --write-out '%{http_code}' \
      --request POST "${API_BASE_URL}/api/public/profiles/${PROFILE_ID}/messages" \
      --header 'Accept: application/json' \
      --header 'Content-Type: application/json' \
      --header "X-Bigmelo-Visitor-Id: ${VISITOR_ID}" \
      --data "$(jq -cn --arg message "${QUESTION}" '{message:$message,audio_response_enabled:false}')")"

    [[ "${HTTP_CODE}" != "429" ]] && break
    sleep 10
  done

  if [[ "${HTTP_CODE}" == "200" ]] && jq -e . "${RESPONSE_FILE}" >/dev/null 2>&1; then
    ANSWER="$(jq -r '.data.text // ""' "${RESPONSE_FILE}")"
    EVIDENCE="$(jq -c '{
      knowledge: (.data.data.chat_ai.response._bigmelo.knowledge // null),
      media: [(.data.media // [])[] | {
        id, provider, provider_label, observation, caption, action_label,
        destination_label, permalink, channel_url, age_restricted
      }],
      products: [(.data.products // [])[] | {id, name, description, public_url, action_url}],
      social_links: [(.data.social_links // [])[] | {provider_key, provider_label, action_label, url}]
    }' "${RESPONSE_FILE}")"
    SEARCHABLE="$(jq -r '[
      (.data.text // ""),
      ((.data.media // []) | tostring),
      ((.data.products // []) | tostring),
      ((.data.social_links // []) | tostring)
    ] | join(" ") | ascii_downcase' "${RESPONSE_FILE}")"
    TERMS_FOUND=true

    while IFS= read -r term; do
      [[ -z "${term}" ]] && continue
      if ! grep -Fqi -- "${term}" <<<"${SEARCHABLE}"; then
        TERMS_FOUND=false
      fi
    done < <(jq -r '.[]' <<<"${MUST_INCLUDE}")

    KNOWLEDGE_MODE="$(jq -r '.data.data.chat_ai.response._bigmelo.knowledge.mode // ""' "${RESPONSE_FILE}")"
    ATTACHMENT_OK=true
    if [[ "${REQUIRE_ATTACHMENT}" != "false" ]]; then
      case "${AREA}" in
        integration)
          [[ "$(jq '(.data.media // []) | length' "${RESPONSE_FILE}")" -gt 0 ]] || ATTACHMENT_OK=false
          ;;
        social_link)
          [[ "$(jq '(.data.social_links // []) | length' "${RESPONSE_FILE}")" -gt 0 ]] || ATTACHMENT_OK=false
          ;;
        product|product_guidance)
          [[ "$(jq '(.data.products // []) | length' "${RESPONSE_FILE}")" -gt 0 ]] || ATTACHMENT_OK=false
          ;;
      esac
    fi

    STATUS=PASS
    NOTES=""
    if [[ -z "${ANSWER}" || "${KNOWLEDGE_MODE}" != "rag" ]]; then
      STATUS=FAIL
      NOTES="La respuesta no fue textual o no informó modo RAG."
    elif [[ "${TERMS_FOUND}" != true ]]; then
      STATUS=FAIL
      NOTES="No aparecieron todos los términos centinela esperados."
    elif [[ "${ATTACHMENT_OK}" != true ]]; then
      STATUS=FAIL
      NOTES="La respuesta no incluyó la tarjeta o el botón esperado para el área."
    fi

    jq -cn \
      --arg id "${ID}" \
      --arg area "${AREA}" \
      --arg key "${KEY}" \
      --arg question "${QUESTION}" \
      --arg status "${STATUS}" \
      --arg response "${ANSWER}" \
      --arg expected_ui "${EXPECTED_UI}" \
      --arg require_attachment "${REQUIRE_ATTACHMENT}" \
      --arg notes "${NOTES}" \
      --argjson must_include "${MUST_INCLUDE}" \
      --argjson evidence "${EVIDENCE}" \
      '{id:$id,area:$area,key:$key,question:$question,status:$status,response:$response,
        expected_ui:$expected_ui,must_include:$must_include,
        require_attachment:(if $require_attachment == "auto" then null else ($require_attachment == "true") end),
        evidence:$evidence,
        notes:(if $notes == "" then null else $notes end)}' >>"${RESULTS_JSONL}"
  else
    ERROR_MESSAGE="$(jq -r '.message // .error // "Respuesta HTTP no válida"' "${RESPONSE_FILE}" 2>/dev/null || true)"
    jq -cn \
      --arg id "${ID}" \
      --arg area "${AREA}" \
      --arg key "${KEY}" \
      --arg question "${QUESTION}" \
      --arg status BLOCKED \
      --arg notes "HTTP ${HTTP_CODE}: ${ERROR_MESSAGE}" \
      '{id:$id,area:$area,key:$key,question:$question,status:$status,response:null,evidence:null,notes:$notes}' \
      >>"${RESULTS_JSONL}"
  fi

  echo "[${CURRENT}/${TOTAL}] ${ID}: $(tail -n 1 "${RESULTS_JSONL}" | jq -r '.status')"
  [[ "${CURRENT}" -lt "${TOTAL}" ]] && sleep "${DELAY_SECONDS}"
done < <(jq -c '.groups[] as $group | $group.questions[] | {
  area: $group.area,
  key: $group.key,
  must_include: (.must_include // $group.must_include // []),
  require_attachment: (if .require_attachment == null then null else .require_attachment end),
  expected_ui: ($group.expected_ui // null),
  id: .id,
  question: .text
}' "${QUESTIONS_FILE}")

FINISHED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
jq -n \
  --arg run_id "${RUN_ID}" \
  --arg started_at "${STARTED_AT}" \
  --arg finished_at "${FINISHED_AT}" \
  --slurpfile results "${RESULTS_JSONL}" \
  '{run_id:$run_id,started_at:$started_at,finished_at:$finished_at,environment:"local",
    summary:{
      total:($results|length),
      passed:([$results[]|select(.status=="PASS")]|length),
      failed:([$results[]|select(.status=="FAIL")]|length),
      blocked:([$results[]|select(.status=="BLOCKED")]|length)
    },results:$results}' >"${OUTPUT_FILE}"

echo "Results: ${OUTPUT_FILE}"
jq '.summary' "${OUTPUT_FILE}"
