<?php

declare(strict_types=1);

namespace EdgarSedov\AgentReadyInstaller\Install\Support;

use InvalidArgumentException;

final class PathInput
{
    private function __construct() {}

    public static function relativeOption(string $name, string $path): string
    {
        $path = trim($path);
        $path = str_replace('\\', '/', $path);

        if ($path === '' || str_starts_with($path, '/') || preg_match('#^[A-Za-z]:/#', $path) === 1) {
            self::invalidRelativeOption($name);
        }

        $path = trim($path, '/');
        $segments = explode('/', $path);

        if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            self::invalidRelativeOption($name);
        }

        return $path;
    }

    public static function absoluteOption(string $name, string $path): string
    {
        $path = rtrim(str_replace('\\', '/', trim($path)), '/');

        if ($path === '' || ! str_starts_with($path, '/')) {
            throw new InvalidArgumentException("Option --{$name} must be an absolute path.");
        }

        return $path;
    }

    public static function hostnameOption(string $name, string $host): string
    {
        $host = strtolower(trim($host));

        if ($host === '' || str_contains($host, '/') || str_contains($host, ':') || preg_match('/\s/', $host) === 1) {
            self::invalidHostnameOption($name);
        }

        $labels = explode('.', $host);

        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63 || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
                self::invalidHostnameOption($name);
            }
        }

        return $host;
    }

    public static function domainOption(string $name, string $domain): string
    {
        return self::hostnameOption($name, $domain);
    }

    private static function invalidRelativeOption(string $name): never
    {
        throw new InvalidArgumentException("Option --{$name} must be a non-empty relative path without absolute, '.' or '..' segments.");
    }

    private static function invalidHostnameOption(string $name): never
    {
        throw new InvalidArgumentException("Option --{$name} must be a hostname without a scheme, port, path, whitespace, or invalid DNS labels.");
    }
}
