#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API_ROOT="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
APPS_ROOT="$(cd "${API_ROOT}/.." && pwd)"

cd "${API_ROOT}"
docker compose exec -T app php artisan test
docker compose exec -T app vendor/bin/pint --test \
  app/Classes/ProfileKnowledge/ProfileCvImporter.php \
  app/Classes/ProfileKnowledge/ProfileDataSynchronizer.php \
  app/Classes/ProfileKnowledge/ProfileQualityAnalyzer.php \
  app/Classes/ProfileKnowledgeAIService/OpenAI/OpenAIProfileKnowledgeClient.php \
  app/Enums/ProfileSourceStatus.php \
  app/Http/Controllers/api/v1/ProfileIntegrationController.php \
  app/Http/Controllers/api/v1/ProfileKnowledgeController.php \
  app/Http/Responses/ProfileKnowledge/ProfileSourceResponse.php \
  app/Jobs/ProfileKnowledge/SynchronizeProfileSource.php \
  app/Models/ProfileSource.php \
  app/Services/Integrations/InstagramIntegrationService.php \
  app/Services/Integrations/OnlyFansIntegrationService.php \
  app/Services/Integrations/OtherIntegrationService.php \
  app/Services/Integrations/TikTokIntegrationService.php \
  app/Services/Integrations/YouTubeIntegrationService.php \
  app/Services/ProfileKnowledge/ProfileIntegrationKnowledgeLifecycle.php \
  app/Services/ProfileKnowledge/ProfileKnowledgeDocumentBuilder.php \
  app/Services/ProfileKnowledge/ProfileSourceLifecycleService.php \
  database/migrations/2026_08_07_000001_add_processing_state_to_profile_sources.php \
  routes/api/v1/api.php \
  tests/Feature/Http/Controllers/api/v1/ProfileKnowledgeControllerTest.php \
  tests/Feature/ProfileKnowledge/ProfileIntegrationKnowledgeLifecycleTest.php \
  tests/Feature/ProfileKnowledge/ProfileSourceLifecycleTest.php \
  tests/TestCase.php \
  tests/Unit/Classes/ProfileKnowledgeAIService/OpenAI/OpenAIProfileKnowledgeClientTest.php

cd "${APPS_ROOT}/voitity-admin/src"
npm run typecheck
npm run lint
npm run build

cd "${APPS_ROOT}/voitity-web/src"
npm run build
