<?php
declare(strict_types=1);

namespace Galaxy\Routing;

use Galaxy\Routing\Input\InputParserInterface;
use Galaxy\Routing\Exception\RouteNotFoundException;

use Galaxy\Result\GResult;

final class GRouter
{

    public function __construct(
        private readonly RouteTable $table,
        private readonly InputParserInterface $parser,
    ) {
    }

    public function dispatch(mixed $input): GResult
    {

        $key = $this->parser->extractKey($input);
        $targetId = $key === null ? null : $this->table->resolve($key);
        if ($targetId === null) {
            throw new RouteNotFoundException('No matching route.');
        }

        $class = $this->table->getAdapterClass($targetId);
        $options = $this->table->getAdapterOptions($targetId);
        $adapter = new $class($options);
        return $adapter->dispatch($input);

    }

}
