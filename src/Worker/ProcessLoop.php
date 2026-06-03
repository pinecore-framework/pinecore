<?php

namespace Pinecore\Pinecore\Worker;

use Pinecore\Pinecore\WorkerLifecycle;

/**
 * Runtime-neutral long-running worker loop. Owns the skeleton every worker type
 * needs: signal-driven graceful shutdown (SIGTERM/SIGINT), bounded lifetime
 * (maxRequests), per-iteration GC, and the shared WorkerLifecycle flag — which
 * also lets repositories recycle the worker on a lost DB connection.
 *
 * HTTP (WorkerRunner), queue consumers, and scheduler ticks all drive the same
 * loop by passing a tick callable; only the tick differs per runtime:
 *
 *   $loop->run(function () use ($queue): bool {
 *       $msg = $queue->pop();           // blocking or null
 *       if ($msg !== null) {
 *           $this->handle($msg);
 *       }
 *       return true;                    // keep consuming
 *   });
 */
final class ProcessLoop
{
    public function __construct(
        private readonly WorkerLifecycle $lifecycle,
        private readonly int $maxRequests = 0,
    ) {}

    /**
     * Runs $tick repeatedly until it returns false, the lifecycle is flagged for
     * shutdown (signal or DB-connection loss), or maxRequests is reached.
     *
     * $tick must handle its own errors — an uncaught throwable exits the loop and
     * therefore the worker, which the supervising runtime restarts. That is the
     * intended recovery path, not a bug.
     *
     * @param callable():bool $tick one unit of work; return false to stop the loop
     */
    public function run(callable $tick): void
    {
        $this->installSignalHandlers();

        for ($n = 0; !$this->maxRequests || $n < $this->maxRequests; ++$n) {
            $keepRunning = $tick();
            gc_collect_cycles();

            if ($keepRunning === false || $this->lifecycle->shouldShutdown()) {
                break;
            }
        }
    }

    /**
     * Request the loop to exit after the current iteration. Equivalent to a
     * SIGTERM; also called from signal handlers and from repositories when the
     * database connection is lost.
     */
    public function requestShutdown(): void
    {
        $this->lifecycle->requestShutdown();
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->lifecycle->requestShutdown());
        pcntl_signal(SIGINT,  fn () => $this->lifecycle->requestShutdown());
    }
}
