<?php
// src/PendingAuthorization.php
// The PKCE verifier + state a caller must persist (Laravel Session) between the redirect and
// the callback -- never round-tripped through the client's own browser-visible state.

namespace Onehux\Sso;

final class PendingAuthorization
{
    public function __construct(
        public readonly string $codeVerifier,
        public readonly string $state,
        public readonly string $authorizationUrl,
    ) {
    }
}
