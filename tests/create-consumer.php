<?php
declare(strict_types=1);

if (count($argv) !== 3) {
    throw new RuntimeException('Usage: create-consumer.php <empty-consumer-root> <package-root>');
}
$consumer = realpath($argv[1]);
$package = realpath($argv[2]);
if ($consumer === false || $package === false || !is_file($package . '/composer.json')) {
    throw new RuntimeException('Existing consumer and package directories are required.');
}
if (file_exists($consumer . '/composer.json')) {
    throw new RuntimeException('Consumer manifest already exists.');
}
$manifest = [
    'name' => 'galaxy-routing-check/consumer',
    'license' => 'proprietary',
    'repositories' => [[
        'type' => 'path',
        'url' => str_replace('\\', '/', $package),
        'options' => ['symlink' => false, 'versions' => ['denetorus/galaxy-routing' => 'dev-ci']],
    ]],
    'require' => ['php' => '>=8.4', 'denetorus/galaxy-routing' => 'dev-ci'],
    'config' => ['allow-plugins' => false],
];
file_put_contents(
    $consumer . '/composer.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL
);
