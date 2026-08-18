<?php
// src/PublicApplication.php
// One entry from GET /api/v1/organizations/{slug}/public-applications/ -- deliberately only
// name/logoUrl/homeUrl, matching exactly what that endpoint returns. No clientId, no slug, no
// OAuth-relevant identifier: a pure "what can I launch" list, not a way to start a sign-in flow.

namespace Onehux\Sso;

final class PublicApplication
{
    public function __construct(
        public readonly string $name,
        public readonly string $logoUrl,
        public readonly string $homeUrl,
    ) {
    }
}
