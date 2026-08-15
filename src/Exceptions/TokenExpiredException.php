<?php
// src/Exceptions/TokenExpiredException.php
// GET /api/v1/oauth/userinfo/ rejected the access token.
//
// OneHux Accounts does not currently issue a refresh token (access tokens are a 15-minute,
// single-issue lifetime) -- this is a real, permanent platform constraint, not a bug in this
// client. Callers must catch this and route the user back through
// OneHuxClient::startAuthorization() for a fresh login; there is no silent-refresh path to
// fall back to.

namespace Onehux\Sso\Exceptions;

class TokenExpiredException extends OneHuxSSOException
{
}
