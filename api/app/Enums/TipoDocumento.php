<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipo de documento con que se identifica a una persona en la puerta.
 *
 * POR QUÉ ES UN ENUM Y NO TEXTO LIBRE
 * -----------------------------------
 * Porque el documento se escribe una vez y después se compara, se busca y se
 * cuenta. Con texto libre aparecen "run", "R.U.N.", "Run" y "RUT" en cuatro
 * filas y ninguna cuenta con las otras tres.
 *
 * Este enum lista los tipos de documento que una persona declara al
 * identificarse en Chile. NO es una lista oficial de tipos de documento
 * vigentes: no se verificó el texto legal que la respalda. Si el centro
 * acepta un tipo que no está aquí, se agrega al enum y a la migración del
 * dominio, no se escribe en el campo.
 */
enum TipoDocumento: string
{
    case Rut = 'rut';

    case Cedula = 'cedula';

    case Pasaporte = 'pasaporte';

    /** Sin documento. La retirada queda registrada igual, con el motivo. */
    case SinDocumento = 'sin_documento';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Rut => 'RUT',
            self::Cedula => 'Cédula de identidad',
            self::Pasaporte => 'Pasaporte',
            self::SinDocumento => 'Sin documento',
        };
    }

    /**
     * Si con este tipo se puede hacer la validación de formato.
     *
     * `SinDocumento` no: no hay nada que validar. Y la validación de formato NO
     * es una validación de que el documento exista ni de que corresponda a esa
     * persona: eso no lo hace el sistema en ninguna de sus versiones.
     */
    public function validable(): bool
    {
        return $this !== self::SinDocumento;
    }
}