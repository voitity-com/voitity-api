#!/usr/bin/env bash

set -euo pipefail

qa_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
api_root="$(cd "${qa_dir}/../.." && pwd)"

cd "${api_root}"

docker compose exec -T app php artisan test \
  tests/Feature/Http/Controllers/api/v1/ProfileControllerTest.php \
  tests/Feature/Http/Controllers/api/v1/AvatarControllerTest.php \
  tests/Feature/Http/Controllers/api/v1/VoiceControllerTest.php \
  tests/Feature/Http/Controllers/api/v1/VoiceSampleControllerTest.php \
  tests/Feature/Http/Controllers/api/v1/ProfileKnowledgeControllerTest.php \
  tests/Feature/Http/Controllers/api/v1/ProfileIntegrationControllerTest.php \
  tests/Feature/Http/Controllers/api/v1/ProfileOtherIntegrationControllerTest.php \
  tests/Feature/Http/Controllers/api/v1/ProfileProductControllerTest.php \
  tests/Feature/Http/Controllers/api/v1/ProfileChatControllerTest.php \
  tests/Feature/Http/Controllers/api/v1/ProfileInsightsControllerTest.php \
  tests/Feature/Http/Controllers/api/v1/SubscriptionLimitsControllerTest.php \
  tests/Unit/Classes/Subscriptions/SubscriptionEntitlementServiceTest.php \
  tests/Feature/Http/Controllers/api/v1/AdminUserControllerTest.php

