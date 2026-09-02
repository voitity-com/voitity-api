# Other profile integration

`Other` is a manual profile integration for owned images/videos that link to an external destination. The first upload creates the `profile_integrations` row with provider `other`; no OAuth connection step is required.

## Storage

All files use `OTHER_MEDIA_DISK` (`profiles` by default) and are written under:

```text
integrations/other/{profile_id}/{uuid}/media.{extension}
```

With `FILESYSTEM_PROFILES_DRIVER=local`, Laravel stores the object under `storage/app/public` and serves it through `/storage`. With `FILESYSTEM_PROFILES_DRIVER=s3`, the same code writes to `AWS_PROFILES_BUCKET`. Objects keep the bucket-owner ACL; production grants anonymous `GET`/`HEAD` centrally and only for `integrations/other/*` through `ProfilesBucketPolicy` in `infra/cloudformation/bigmelo-prod.yml`.

Validation must cover both halves of the flow: the authenticated upload must succeed and the resulting `media_url` must return HTTP `200` without AWS credentials. A successful database write does not prove that browsers can display the object. The same policy mechanism covers `integrations/onlyfans/*`; private source prefixes remain excluded.

Supported files are JPG, PNG, WEBP, GIF (10 MB by default), and MP4, MOV, WEBM (100 MB by default). Upload and deletion use the configured disk, so deleting media or disconnecting the integration removes both local and S3 objects.

## Destinations and languages

Clients load the current catalog from `GET /api/profile/integration-destinations?locale=es|en`. Media persists stable codes in metadata:

- `destination_type`
- `action_type`
- `custom_destination_label` only when `destination_type=other`

Translated `destination_label` and `action_label` values are computed in API responses. They are not persisted, so adding a locale or changing copy does not require a data migration. Add new destinations to `config/integration-destinations.php` and a new locale to both the catalog config and clients.

## Authenticated endpoints

- `GET /api/profile/{profile}/integrations/other/media`
- `POST /api/profile/{profile}/integrations/other/media`
- `PATCH /api/profile/{profile}/integrations/other/media/{media}`
- `PUT /api/profile/{profile}/integrations/other/media-selection`
- `DELETE /api/profile/{profile}/integrations/other/media/{media}`
- `DELETE /api/profile/{profile}/integrations/other`

Read endpoints require `profile:read`; mutations require `profile:write`. Owners and administrators may manage the profile. Structured application logs are emitted after uploads, updates, selection changes, deletions, and disconnects without logging external URLs or file contents.
