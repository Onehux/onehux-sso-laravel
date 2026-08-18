<?php
// src/Exceptions/OrganizationNotFoundException.php
// GET /api/v1/organizations/{slug}/public-applications/ returned a non-2xx response -- no
// Organization matches that slug, or it isn't usable (deactivated/deleted). Carries the real
// error_description from the backend rather than a generic message.

namespace Onehux\Sso\Exceptions;

class OrganizationNotFoundException extends OneHuxSSOException
{
    public readonly string $errorDescription;

    public function __construct(string $errorDescription)
    {
        parent::__construct($errorDescription);
        $this->errorDescription = $errorDescription;
    }
}
