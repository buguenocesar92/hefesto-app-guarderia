<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de un pago.
 *
 * LO QUE NO ESTÁ ACÁ, Y POR QUÉ
 * ------------------------------
 * No hay estado "pendiente de confirmar" ni "en revisión". Un pago que entra en
 * el sistema es un pago que alguien registró, con su monto y su fecha. Que ese
 * pago corresponda a lo que la mensualidad dice es un problema de cuentas, y se
 * resuelve mirando el saldo.
 *
 * El saldo se calcula: precio del plan menos pagos no anulados. No hay una
 * columna `saldo` que alguien mantenga a mano. Ver `App\Models\Matricula`.
 *
 * Un pago anulado conserva la fila. Anular no es borrar: es dejar constancia de
 * que se creó un pago y después se dejó sin efecto, con un motivo escrito.
 */
enum EstadoPago: string
{
    case Registrado = 'registrado';

    case Anulado = 'anulado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Registrado => 'Registrado',
            self::Anulado => 'Anulado',
        };
    }

    /**
     * Si este pago cuenta para el saldo.
     */
    public function sumaAlSaldo(): bool
    {
        return $this === self::Registrado;
    }
}