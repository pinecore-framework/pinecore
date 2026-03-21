<?php

namespace Pronchev\Pinecore\Http;

use Psr\Container\ContainerInterface;

final class MiddlewarePipeline
{
    public function __construct(private readonly ContainerInterface $container) {}

    /**
     * Run the given middleware classes in order, passing the request through each.
     *
     * @param list<class-string<MiddlewareInterface>> $middlewareClasses
     */
    public function run(Request $request, array $middlewareClasses): Request
    {
        foreach ($middlewareClasses as $class) {
            /** @var MiddlewareInterface $middleware */
            $middleware = $this->container->get($class);
            $request    = $middleware->process($request);
        }
        return $request;
    }
}
