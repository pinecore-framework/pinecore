<?php

namespace Pinecore\Pinecore;

/**
 * Shared, dependency-free shutdown flag for the worker loop. WorkerRunner reads
 * it after each iteration; repositories (and signal handlers) set it. When a
 * database connection is lost mid-request, the repository flags the worker for
 * graceful recycling so the runtime (RoadRunner/FrankenPHP/...) spawns a fresh
 * worker with a healthy connection instead of failing every subsequent request.
 */
final class WorkerLifecycle
{
    private bool $shutdownRequested = false;

    public function requestShutdown(): void
    {
        $this->shutdownRequested = true;
    }

    public function shouldShutdown(): bool
    {
        return $this->shutdownRequested;
    }
}
