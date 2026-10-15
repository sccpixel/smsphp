<?php
declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Relógio da aplicação, sempre no fuso APP_TIMEZONE.
 * Injetável: nos testes pode substituir-se por um relógio com hora fixa.
 * Todas as datas gravadas na BD vêm daqui (nunca de NOW() do MySQL).
 */
final class Clock
{
    private readonly DateTimeZone $zone;

    public function __construct(string $timezone)
    {
        $this->zone = new DateTimeZone($timezone);
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->zone);
    }
}
