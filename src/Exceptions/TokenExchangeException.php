<?php
// src/Exceptions/TokenExchangeException.php
// POST /api/v1/oauth/token/ returned a non-2xx response. Carries the real OAuth
// error/error_description from oauth.views._error_response() rather than a generic message,
// so a caller can distinguish e.g. invalid_grant (expired code) from invalid_client
// (misconfigured client_id/secret).

namespace Onehux\Sso\Exceptions;

class TokenExchangeException extends OneHuxSSOException
{
    public readonly string $error;
    public readonly string $errorDescription;
    public readonly int $statusCode;

    public function __construct(string $error, string $errorDescription, int $statusCode)
    {
        parent::__construct("{$error}: {$errorDescription}");
        $this->error = $error;
        $this->errorDescription = $errorDescription;
        $this->statusCode = $statusCode;
    }
}
