<?php
declare(strict_types=1);

namespace App\Domain\Approval;

/**
 * Estado de um pedido de aprovação. Só se sai de Pending; os outros são finais.
 *
 *   pending -> approved | rejected | expired | cancelled
 */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
