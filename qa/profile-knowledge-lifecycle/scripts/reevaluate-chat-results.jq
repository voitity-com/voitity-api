def manifest_questions($manifest):
  reduce (
    $manifest.groups[] as $group
    | $group.questions[]
    | {
        key: .id,
        value: {
          expected_ui: (.expected_ui // $group.expected_ui // null),
          must_include: (.must_include // $group.must_include // []),
          require_attachment: (
            if .require_attachment == null then null else .require_attachment end
          )
        }
      }
  ) as $entry ({}; .[$entry.key] = $entry.value);

def attachment_ok($area; $evidence; $required):
  if $required == false then true
  elif $area == "integration" then (($evidence.media // []) | length) > 0
  elif $area == "social_link" then (($evidence.social_links // []) | length) > 0
  elif $area == "product" or $area == "product_guidance" then (($evidence.products // []) | length) > 0
  else true
  end;

def reviewed_result($expectations):
  . as $result
  | ($expectations[$result.id] // {}) as $expected
  | ($expected.must_include // []) as $terms
  | ([
      ($result.response // ""),
      (($result.evidence.media // []) | tojson),
      (($result.evidence.products // []) | tojson),
      (($result.evidence.social_links // []) | tojson)
    ] | join(" ") | ascii_downcase) as $searchable
  | (all($terms[]; (. | ascii_downcase) as $term | $searchable | contains($term))) as $terms_found
  | (attachment_ok($result.area; $result.evidence; $expected.require_attachment)) as $attachment_found
  | .automated_status = .status
  | .must_include = $terms
  | .expected_ui = ($expected.expected_ui // null)
  | .require_attachment = ($expected.require_attachment // null)
  | if .status == "BLOCKED" then .
    elif (.response // "") == "" or .evidence.knowledge.mode != "rag" then
      .status = "FAIL" | .notes = "La respuesta no fue textual o no informó modo RAG."
    elif $terms_found | not then
      .status = "FAIL" | .notes = "No aparecieron todos los términos esperados para esta pregunta."
    elif $attachment_found | not then
      .status = "FAIL" | .notes = "La respuesta no incluyó la tarjeta o el botón esperado para esta pregunta."
    else
      .status = "PASS" | .notes = null
    end;

manifest_questions($manifest[0]) as $expectations
| .results |= map(reviewed_result($expectations))
| .summary = {
    total: (.results | length),
    passed: ([.results[] | select(.status == "PASS")] | length),
    failed: ([.results[] | select(.status == "FAIL")] | length),
    blocked: ([.results[] | select(.status == "BLOCKED")] | length)
  }
