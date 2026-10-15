<?php
declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/** Operação incompatível com o estado atual (ex.: cancelar um pedido já fechado) -> HTTP 409. */
final class ConflictException extends RuntimeException
{
}
