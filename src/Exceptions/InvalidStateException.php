<?php
// src/Exceptions/InvalidStateException.php
// The callback's state parameter didn't match what was stashed at redirect time, or
// code/state was missing outright -- a real CSRF-protection failure, or a stale/replayed
// callback URL.

namespace Onehux\Sso\Exceptions;

class InvalidStateException extends OneHuxSSOException
{
}
