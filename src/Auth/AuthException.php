<?php

namespace Pinecore\Pinecore\Auth;

use RuntimeException;

final class AuthException extends RuntimeException implements AuthFailureInterface {}
