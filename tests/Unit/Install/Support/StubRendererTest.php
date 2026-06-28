<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Tests\Unit\Install\Support;

use AgentsReady\LaravelInstaller\Install\Support\StubRenderer;
use AgentsReady\LaravelInstaller\Tests\UnitTestCase;
use RuntimeException;

final class StubRendererTest extends UnitTestCase
{
    public function test_render_replaces_literal_tokens_only(): void
    {
        $root = $this->makeStubRoot([
            'hello.stub' => "Hello {{ name }}.\n{{ missing }}\n",
        ]);

        $rendered = (new StubRenderer($root))->render('hello.stub', [
            'name' => 'Agents-Ready',
        ]);

        $this->assertSame("Hello Agents-Ready.\n{{ missing }}\n", $rendered);
    }

    public function test_render_fails_with_clear_message_when_stub_is_missing(): void
    {
        $renderer = new StubRenderer($this->makeStubRoot());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to read stub [missing.stub].');

        $renderer->render('missing.stub');
    }

    /**
     * @param  array<string, string>  $files
     */
    private function makeStubRoot(array $files = []): string
    {
        $root = $this->temporaryDirectory('agents-ready-stub-renderer-test-');

        foreach ($files as $path => $content) {
            $target = $root.'/'.$path;
            $directory = dirname($target);

            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            file_put_contents($target, $content);
        }

        return $root;
    }
}
