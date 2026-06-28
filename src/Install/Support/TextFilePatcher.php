<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Install\Support;

use RuntimeException;

final class TextFilePatcher
{
    /**
     * @param  array<int, string>  $lines
     */
    public function appendMissingLines(string $root, string $path, array $lines): bool
    {
        $existing = $this->read($root, $path);
        $existingLines = preg_split('/\R/', $existing) ?: [];
        $missing = array_values(array_filter(
            $lines,
            static fn (string $line): bool => ! in_array($line, $existingLines, true),
        ));

        if ($missing === []) {
            return false;
        }

        $this->write($root, $path, $this->append($existing, implode("\n", $missing)."\n"));

        return true;
    }

    public function upsertMarkedBlock(string $root, string $path, string $startMarker, string $endMarker, string $body): bool
    {
        $existing = $this->read($root, $path);
        $block = $this->markedBlock($startMarker, $endMarker, $body);

        if ($existing === '') {
            $patched = $block;
        } elseif (! str_contains($existing, $startMarker) && ! str_contains($existing, $endMarker)) {
            $patched = $this->append($existing, $block);
        } else {
            $patched = $this->replaceMarkedBlock($path, $existing, $startMarker, $endMarker, $block);
        }

        if ($patched === $existing) {
            return false;
        }

        $this->write($root, $path, $patched);

        return true;
    }

    private function replaceMarkedBlock(string $path, string $existing, string $startMarker, string $endMarker, string $block): string
    {
        $start = strpos($existing, $startMarker);
        $end = strpos($existing, $endMarker);

        if ($start === false || $end === false || $end < $start) {
            throw new RuntimeException("Unable to patch [{$path}] because the Agents-Ready managed block markers are incomplete.");
        }

        $end += strlen($endMarker);
        $after = substr($existing, $end);

        if (str_starts_with($after, "\r\n")) {
            $end += 2;
        } elseif (str_starts_with($after, "\n")) {
            $end += 1;
        }

        return substr($existing, 0, $start).$block.substr($existing, $end);
    }

    private function markedBlock(string $startMarker, string $endMarker, string $body): string
    {
        return $startMarker."\n".rtrim($body)."\n".$endMarker."\n";
    }

    private function append(string $existing, string $addition): string
    {
        if ($existing === '') {
            return $addition;
        }

        $prefix = str_ends_with($existing, "\n") ? '' : "\n";

        return $existing.$prefix."\n".$addition;
    }

    private function read(string $root, string $path): string
    {
        $target = $this->absolutePath($root, $path);

        return is_file($target) ? (string) file_get_contents($target) : '';
    }

    private function write(string $root, string $path, string $content): void
    {
        $target = $this->absolutePath($root, $path);
        $directory = dirname($target);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create directory [{$directory}].");
        }

        if (file_put_contents($target, $content) === false) {
            throw new RuntimeException("Unable to write [{$path}].");
        }
    }

    private function absolutePath(string $root, string $path): string
    {
        return rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
