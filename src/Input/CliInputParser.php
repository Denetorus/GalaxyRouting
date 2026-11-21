<?php
declare(strict_types=1);

namespace Galaxy\Routing\Input;

final class CliInputParser implements InputParserInterface
{

    public function extractKey(mixed $input): ?array
    {

        if (!is_array($input) || !array_is_list($input)) {
            return null;
        }
        foreach ($input as $token) {
            if (!is_string($token)) {
                return null;
            }
        }

        if ($input === []) {
            return ['list'];
        }
        if ($input === ['help'] || $input === ['--help']) {
            return ['help'];
        }
        $command = $input[0] === 'help' ? ($input[1] ?? '') : $input[0];
        if ($command === '' || str_starts_with($command, '-')) {
            return null;
        }
        $key = explode(':', $command);
        return in_array('', $key, true) ? null : $key;

    }

}
