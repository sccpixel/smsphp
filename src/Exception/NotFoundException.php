<?php
declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/** Recurso não encontrado -> HTTP 404. */
final class NotFoundException extends RuntimeException
{
}
