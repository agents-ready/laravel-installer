<?php

declare(strict_types=1);

namespace EdgarSedov\AgentReadyInstaller\Tests\Support;

trait WritesLaravelComposerJson
{
    protected function writeLaravelComposerJson(string $root, string $name = 'example/fresh-laravel-app'): void
    {
        file_put_contents(
            $root.'/composer.json',
            json_encode($this->laravelComposerJson($name), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function laravelComposerJson(string $name = 'example/fresh-laravel-app'): array
    {
        return [
            'name' => $name,
            'type' => 'project',
            'description' => 'A Laravel application.',
            'license' => 'MIT',
            'require' => [
                'php' => '^8.4',
                'laravel/framework' => '^13.0',
                'laravel/tinker' => '^2.10',
            ],
            'require-dev' => [
                'fakerphp/faker' => '^1.24',
                'laravel/pint' => '^1.25',
                'mockery/mockery' => '^1.6',
                'nunomaduro/collision' => '^8.8',
                'phpunit/phpunit' => '^12.0',
            ],
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'app/',
                    'Database\\Factories\\' => 'database/factories/',
                    'Database\\Seeders\\' => 'database/seeders/',
                ],
            ],
            'autoload-dev' => [
                'psr-4' => [
                    'Tests\\' => 'tests/',
                ],
            ],
            'scripts' => [
                'post-autoload-dump' => [
                    'Illuminate\\Foundation\\ComposerScripts::postAutoloadDump',
                    '@php artisan package:discover --ansi',
                ],
                'post-root-package-install' => [
                    '@php -r "file_exists(\'.env\') || copy(\'.env.example\', \'.env\');"',
                ],
                'post-create-project-cmd' => [
                    '@php artisan key:generate --ansi',
                ],
            ],
            'extra' => [
                'laravel' => [
                    'dont-discover' => [],
                ],
            ],
            'config' => [
                'optimize-autoloader' => true,
                'preferred-install' => 'dist',
                'sort-packages' => true,
            ],
            'minimum-stability' => 'stable',
            'prefer-stable' => true,
        ];
    }
}
