<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EstadoAutorizacion;

/**
 * Lo que la interfaz puede mostrar sobre el estado de una autorización.
 *
 * POR QUÉ ES UNA CLASE Y NO UN STRING
 * ----------------------------------
 * Porque la diferencia entre "vencida" y "sin autorización" es la diferencia
 * entre una explicación y un vacío. Y porque "no encontrada" tiene que poder
 * viajar sin parecer un estado de autorización.
 *
 * Es un tipo cerrado a propósito: si alguien agrega un caso nuevo, tiene que
 * decidir cómo se muestra, y esa decisión no puede quedar implícita.
 */
enum EstadoAutorizacionLectura: string
{
    case Vigente = 'vigente';

    case Vencida = 'vencida';

    case Anulada = 'anulada';

    case NoEncontrada = 'no_encontrada';

    public static function desde(EstadoAutorizacion $estado): self
    {
        return match ($estado) {
            EstadoAutorizacion::Vigente => self::Vigente,
            EstadoAutorizacion::Vencida => self::Vencida,
            EstadoAutorizacion::Anulada => self::Anulada,
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Vigente => 'Vigente',
            self::Vencida => 'Vencida',
            self::Anulada => 'Anulada',
            self::NoEncontrada => 'No encontrada',
        };
    }

    /**
     * Si con este estado se puede retirar al niño.
     *
     * La interfaz usa esto para decidir el COLOR: verde si se puede, rojo
     * grande si no. Ver `pages/retiros.vue`.
     */
    public function permiteRetiro(): bool
    {
        return $this === self::Vigente;
    }
}