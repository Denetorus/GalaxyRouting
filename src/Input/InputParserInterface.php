<?php
declare(strict_types=1);

namespace Galaxy\Routing\Input;

interface InputParserInterface
{
    public function extractKey(mixed $input): ?array;
}
