<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Tests\Unit\Install\Support;

use AgentsReady\LaravelInstaller\Install\Support\PathInput;
use AgentsReady\LaravelInstaller\Tests\UnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

final class PathInputTest extends UnitTestCase
{
    public function test_relative_option_normalizes_safe_relative_paths(): void
    {
        $this->assertSame('tools/agents-ready', PathInput::relativeOption('tools-path', ' tools\\agents-ready/'));
    }

    #[DataProvider('unsafeRelativePaths')]
    public function test_relative_option_rejects_unsafe_paths(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Option --tools-path must be a non-empty relative path without absolute, '.' or '..' segments.");

        PathInput::relativeOption('tools-path', $path);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeRelativePaths(): array
    {
        return [
            'empty' => [''],
            'absolute unix path' => ['/tmp/tools'],
            'absolute windows path' => ['C:\\tmp\\tools'],
            'current directory segment' => ['tools/./agents-ready'],
            'parent directory segment' => ['tools/../agents-ready'],
            'empty segment' => ['tools//agents-ready'],
        ];
    }

    public function test_absolute_option_normalizes_absolute_paths_without_requiring_existence(): void
    {
        $this->assertSame('/host/app', PathInput::absoluteOption('host-root', ' /host/app/'));
    }

    public function test_absolute_option_rejects_relative_paths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Option --host-root must be an absolute path.');

        PathInput::absoluteOption('host-root', 'relative/path');
    }

    public function test_domain_option_normalizes_valid_route_domains(): void
    {
        $this->assertSame('nexus.localhost', PathInput::domainOption('route-domain', ' Nexus.Localhost '));
    }

    public function test_project_slug_option_normalizes_user_input(): void
    {
        $this->assertSame('real-app', PathInput::projectSlugOption('project-slug', ' Real App '));
    }

    public function test_project_slug_option_rejects_empty_normalized_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Option --project-slug must include at least one letter or number after normalization.');

        PathInput::projectSlugOption('project-slug', '!!!');
    }

    #[DataProvider('invalidRouteDomains')]
    public function test_domain_option_rejects_invalid_route_domains(string $domain): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Option --route-domain must be a hostname without a scheme, port, path, whitespace, or invalid DNS labels.');

        PathInput::domainOption('route-domain', $domain);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidRouteDomains(): array
    {
        return [
            'empty' => [''],
            'scheme' => ['https://app.localhost'],
            'port' => ['app.localhost:8443'],
            'path' => ['app.localhost/path'],
            'space' => ['app localhost'],
            'empty label' => ['app..localhost'],
            'leading hyphen' => ['-app.localhost'],
            'trailing hyphen' => ['app-.localhost'],
        ];
    }
}
