<?php
declare(strict_types=1);

namespace Tests\Domain\Callback;

use App\Domain\Callback\CallbackSchedule;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CallbackScheduleTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-15 10:00:00');
    }

    public function testDefaultScheduleHasSevenAttempts(): void
    {
        $this->assertSame(7, (new CallbackSchedule([1, 2, 5, 10, 20, 30]))->maxAttempts());
    }

    public function testWaitsGrowAfterEachFailedAttempt(): void
    {
        $schedule = new CallbackSchedule([1, 2, 5, 10, 20, 30]);

        $this->assertEquals(new DateTimeImmutable('2026-10-15 10:01:00'), $schedule->nextAttemptAt(1, $this->now));
        $this->assertEquals(new DateTimeImmutable('2026-10-15 10:02:00'), $schedule->nextAttemptAt(2, $this->now));
        $this->assertEquals(new DateTimeImmutable('2026-10-15 10:05:00'), $schedule->nextAttemptAt(3, $this->now));
        $this->assertEquals(new DateTimeImmutable('2026-10-15 10:30:00'), $schedule->nextAttemptAt(6, $this->now));
    }

    public function testGivesUpAfterTheLastAttempt(): void
    {
        $schedule = new CallbackSchedule([1, 2, 5, 10, 20, 30]);

        $this->assertNull($schedule->nextAttemptAt(7, $this->now));
        $this->assertNull($schedule->nextAttemptAt(50, $this->now));
    }

    public function testEmptyListMeansASingleAttempt(): void
    {
        $schedule = new CallbackSchedule([]);

        $this->assertSame(1, $schedule->maxAttempts());
        $this->assertNull($schedule->nextAttemptAt(1, $this->now));
    }
}
