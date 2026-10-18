<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de la asistencia diaria de un niño.
 *
 * POR QUÉ LA AUSENCIA TIENE MOTIVO Y AVISO
 * ---------------------------------------
 * Porque "no llegó y nadie avisó" es la situación que el sistema tiene que
 * hacer visible. Una ausencia con motivo y con el responsable que avisó es una
 * ausencia; una sin motivo y sin aviso es una situación que hay que mirar hoy,
 * no dentro de un mes.
 *
 * Los tres estados de salida se distinguen porque el registro de retiro que
 * acompaña a cada una es distinto: una salida temporal y un retiro con un
 * responsable autorizado no son lo mismo, y no tienen que quedar iguales en el
 * historial.
 */
enum EstadoAsistencia: string
{
    /** Está en el centro y no se ha registrado su salida. */
    case Presente = 'presente';

    /** Salió y volvió el mismo día. */
    case SalidaTemporal = 'salida_temporal';

    /** Se retiró del centro por el día. */
    case Retirado = 'retirado';

    /** No llegó. */
    case Ausente = 'ausente';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Presente => 'Presente',
            self::SalidaTemporal => 'Salida temporal',
            self::Retirado => 'Retirado',
            self::Ausente => 'Ausente',
        };
    }

    /**
     * Si el niño estuvo efectivamente en el centro ese día.
     *
     * Es lo que la facturación mensual necesita saber, y por eso está en el
     * enum y no en la consulta que arma la pantalla.
     */
    public function estuvoEnElCentro(): bool
    {
        return match ($this) {
            self::Presente, self::SalidaTemporal, self::Retirado => true,
            self::Ausente => false,
        };
    }

    /**
     * Si a esta asistencia le corresponde un registro de retiro.
     *
     * Solo el retiro del día. Una salida temporal no lleva responsable
     * autorizado: el niño va y vuelve dentro del día, y el registro de retiro
     * se reserva para lo que de verdad sale del centro.
     */
    public function admiteRetiro(): bool
    {
        return $this === self::Retirado;
    }
}