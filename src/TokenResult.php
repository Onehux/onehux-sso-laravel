<?php
// src/TokenResult.php
// Mirrors oauth.views.TokenView's real response shape exactly (access_token, id_token,
// token_type, expires_in, scope) -- no fields invented, none dropped.

namespace Onehux\Sso;

final class TokenResult
{
    public function __construct(
        public readonly string $accessToken,
        public readonly string $idToken,
        public readonly string $tokenType,
        public readonly int $expiresIn,
        public readonly string $scope,
    ) {
    }
}
