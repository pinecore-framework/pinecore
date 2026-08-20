<?php

namespace Pinecore\Pinecore\Auth;

/**
 * Marker for exceptions that mean "authentication failed".
 *
 * HttpApplication::dispatch() answers any throwable implementing this interface
 * with 401 and the exception message — the same way it treats the framework's
 * own AuthException. Applications tag their domain exceptions (bad credentials,
 * revoked refresh token, …) with it instead of extending AuthException, which
 * is final on purpose: composition, not inheritance.
 *
 * It is caught before HttpException and \Throwable, so the marker wins over
 * any status code the exception might otherwise carry.
 */
interface AuthFailureInterface
{
}
