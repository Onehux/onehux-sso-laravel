<?php
// src/Exceptions/StepUpRequiredException.php
// POST /api/v1/oauth/token/ returned {"error": "step_up_required", ...} (README.md ADR-076,
// backend repo) -- credentials/code were valid, but the platform's device/location trust gate
// rejected this specific login (password or Google) as coming from an unrecognized
// device/location. NOT a fatal error: OneHuxClient::exchangeCode() throws this distinctly from
// TokenExchangeException so the controller can redirect the browser to complete step-up (magic
// link/email code/passkey) rather than showing a hard failure -- the same automatic-redirect
// behavior the platform's own first-party dashboard uses for this identical error.

namespace Onehux\Sso\Exceptions;

class StepUpRequiredException extends OneHuxSSOException
{
    public readonly string $errorDescription;

    public function __construct(string $errorDescription)
    {
        parent::__construct($errorDescription);
        $this->errorDescription = $errorDescription;
    }
}
