<?php

namespace Pinecore\Pinecore\Http;

interface MiddlewareInterface
{
    /**
     * Process the request and return an enriched (or unchanged) request.
     *
     * Implementations may throw AuthException or HttpException to short-circuit
     * the request — these are caught centrally in HttpApplication::dispatch().
     */
    public function process(Request $request): Request;
}
