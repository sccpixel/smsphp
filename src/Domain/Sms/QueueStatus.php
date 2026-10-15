<?php
declare(strict_types=1);

namespace App\Domain\Sms;

/** Estado de uma linha de sms_queue (coluna `status`). */
enum QueueStatus: int
{
    case Pending = 0;
    case Dispatched = 1;
    case Failed = 2;

    /** Texto usado na API: "pending", "dispatched" ou "failed". */
    public function label(): string
    {
        return strtolower($this->name);
    }
}
