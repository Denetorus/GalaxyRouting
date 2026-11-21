<?php
declare(strict_types=1);

namespace Galaxy\Routing\Adapter;

use Galaxy\Result\GResult;

interface AdapterInterface
{
    public function __construct(array $options = []);

    public function dispatch(mixed $input): GResult;
}
