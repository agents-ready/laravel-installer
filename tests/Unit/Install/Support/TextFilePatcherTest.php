<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Tests\Unit\Install\Support;

use AgentsReady\LaravelInstaller\Install\Support\TextFilePatcher;
use AgentsReady\LaravelInstaller\Tests\UnitTestCase;
use RuntimeException;

final class TextFilePatcherTest extends UnitTestCase
{
    public function test_append_missing_lines_preserves_existing_content_and_is_idempotent(): void
    {
        $root = $this->makeRoot();
        file_put_contents($root.'/.gitignore', "/vendor\n");
        $patcher = new TextFilePatcher;

        $firstPatch = $patcher->appendMissingLines($root, '.gitignore', ['/vendor', '/.agents-ready/']);
        $secondPatch = $patcher->appendMissingLines($root, '.gitignore', ['/vendor', '/.agents-ready/']);

        $this->assertTrue($firstPatch);
        $this->assertFalse($secondPatch);
        $this->assertSame("/vendor\n\n/.agents-ready/\n", file_get_contents($root.'/.gitignore'));
    }

    public function test_upsert_marked_block_appends_to_existing_files_and_is_idempotent(): void
    {
        $root = $this->makeRoot();
        file_put_contents($root.'/AGENTS.md', "# Existing\n\nKeep this.\n");
        $patcher = new TextFilePatcher;

        $firstPatch = $patcher->upsertMarkedBlock($root, 'AGENTS.md', '<!-- start -->', '<!-- end -->', "## Managed\n\nUse ./local.");
        $secondPatch = $patcher->upsertMarkedBlock($root, 'AGENTS.md', '<!-- start -->', '<!-- end -->', "## Managed\n\nUse ./local.");

        $this->assertTrue($firstPatch);
        $this->assertFalse($secondPatch);
        $this->assertSame("# Existing\n\nKeep this.\n\n<!-- start -->\n## Managed\n\nUse ./local.\n<!-- end -->\n", file_get_contents($root.'/AGENTS.md'));
    }

    public function test_upsert_marked_block_replaces_only_existing_marked_block(): void
    {
        $root = $this->makeRoot();
        file_put_contents($root.'/AGENTS.md', "Before\n<!-- start -->\nOld\n<!-- end -->\nAfter\n");

        $patched = (new TextFilePatcher)->upsertMarkedBlock($root, 'AGENTS.md', '<!-- start -->', '<!-- end -->', 'New');

        $this->assertTrue($patched);
        $this->assertSame("Before\n<!-- start -->\nNew\n<!-- end -->\nAfter\n", file_get_contents($root.'/AGENTS.md'));
    }

    public function test_upsert_marked_block_rejects_incomplete_markers(): void
    {
        $root = $this->makeRoot();
        file_put_contents($root.'/AGENTS.md', "Before\n<!-- start -->\nOld\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to patch [AGENTS.md] because the Agents-Ready managed block markers are incomplete.');

        (new TextFilePatcher)->upsertMarkedBlock($root, 'AGENTS.md', '<!-- start -->', '<!-- end -->', 'New');
    }

    private function makeRoot(): string
    {
        return $this->temporaryDirectory('agents-ready-text-patcher-test-');
    }
}
