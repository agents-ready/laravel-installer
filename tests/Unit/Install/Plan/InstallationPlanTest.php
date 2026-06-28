<?php

declare(strict_types=1);

namespace EdgarSedov\AgentReadyInstaller\Tests\Unit\Install\Plan;

use EdgarSedov\AgentReadyInstaller\Install\Operations\WriteFileOperation;
use EdgarSedov\AgentReadyInstaller\Install\Plan\InstallationPlan;
use EdgarSedov\AgentReadyInstaller\Tests\UnitTestCase;
use InvalidArgumentException;

final class InstallationPlanTest extends UnitTestCase
{
    public function test_paths_are_reported_in_operation_order(): void
    {
        $plan = new InstallationPlan([
            WriteFileOperation::conflictIfDifferent('local', ''),
            WriteFileOperation::conflictIfDifferent('docker-compose.yml', ''),
        ]);

        $this->assertSame(['local', 'docker-compose.yml'], $plan->paths());
    }

    public function test_operation_for_returns_the_matching_operation(): void
    {
        $operation = WriteFileOperation::conflictIfDifferent('local', 'content');
        $plan = new InstallationPlan([$operation]);

        $this->assertSame($operation, $plan->operationFor('local'));
    }

    public function test_operation_for_rejects_unknown_paths(): void
    {
        $plan = new InstallationPlan([
            WriteFileOperation::conflictIfDifferent('local', ''),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No planned operation exists for [missing].');

        $plan->operationFor('missing');
    }

    public function test_duplicate_target_paths_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Installation plan contains duplicate target path [local].');

        new InstallationPlan([
            WriteFileOperation::conflictIfDifferent('local', 'one'),
            WriteFileOperation::conflictIfDifferent('local', 'two'),
        ]);
    }

    public function test_nested_targets_under_file_targets_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Installation plan target [local/lib/common.sh] is nested under file target [local].');

        new InstallationPlan([
            WriteFileOperation::conflictIfDifferent('local', ''),
            WriteFileOperation::conflictIfDifferent('local/lib/common.sh', ''),
        ]);
    }
}
