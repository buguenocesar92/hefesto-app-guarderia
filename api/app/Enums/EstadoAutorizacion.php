<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de una autorización de retiro.
 *
 * POR QUÉ SON TRES Y NO UN BOOLEANO "VIGENTE"
 * --------------------------------------------
 * Porque una autorización vencida no es lo mismo que una autorización anulada.
 *
 *   Vencida  la fecha pasó y nadie la renovó. El sistema la muestra EN GRANDE,
 *            porque es el caso que más se repite y el más grave de resolver mal.
 *
 *   Anulada  alguien la dejó sin efecto a propósito, con un motivo escrito. Es
 *            una decisión, y se registra como decisión.
 *
 *   Vigente  está dentro de su ventana de fechas y nadie la anuló.
 *
 * Con un booleano, una autorización vencida y una anulada se verían iguales en
 * pantalla, y son dos cosas que se responden de forma distinta frente a una
 * familia.
 */
enum EstadoAutorizacion: string
{
    case Vigente = 'vigente';

    case Vencida = 'vencida';

    case Anulada = 'anulada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Vigente => 'Vigente',
            self::Vencida => 'Vencida',
            self::Anulada => 'Anulada',
        };
    }

    /**
     * Si con este estado se puede retirar a un niño.
     *
     * La respuesta corta es que no: solo `Vigente` sirve. Y la fecha se comprueba
     * aparte, en `EvaluadorDeAutorizacion`, porque un estado guardado en la fila
     * y una fecha guardada en la fila se pueden desincronizar.
     */
    public function permiteRetiro(): bool
    {
        return $this === self::Vigente;
    }
}