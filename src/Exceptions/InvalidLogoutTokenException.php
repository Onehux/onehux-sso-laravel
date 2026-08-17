<?php
// src/Exceptions/InvalidLogoutTokenException.php
// An incoming POST to the backchannel-logout route failed real OIDC Back-Channel Logout
// validation (spec: openid-connect-backchannel-1_0.html section 2.6) -- bad/missing signature,
// wrong aud, missing/malformed events claim, a present nonce claim (forbidden), an expired
// token, or a missing sub/sid. The controller turns this into the spec-required HTTP 400,
// never a 500 -- a forged or malformed request on a public endpoint is expected adversarial
// input, not a server bug.

namespace Onehux\Sso\Exceptions;

class InvalidLogoutTokenException extends OneHuxSSOException
{
}
