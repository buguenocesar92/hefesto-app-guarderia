<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Grado de una incidencia.
 *
 * La palabra "leve" y la palabra "grave" no significan lo mismo en dos centros,
 * y es la dirección del centro la que decide qué significa acá. Por eso el enum
 * trae cuatro grados con descripciones explícitas y no un número del 1 al 10
 * que parece objetivo y no lo es.
 *
 * Un número del 1 al 10 haría que dos educadoras llamaran "7" a cosas muy
 * distintas, y la conversación con la familia empieza por ahí. Ver docs/02.
 */
enum GravedadIncidencia: string
{
    /** Un hecho menor que se anota y no requiere conversación. */
    case Menor = 'menor';

    /** Requiere avisar a la familia en el día. */
    case Moderada = 'moderada';

    /** Requiere conversación con la familia y queda en registro. */
    case Relevante = 'relevante';

    /** Requiere intervención de la dirección y queda en registro. */
    case Critica = 'critica';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Menor => 'Menor',
            self::Moderada => 'Moderada',
            self::Relevante => 'Relevante',
            self::Critica => 'Crítica',
        };
    }

    /**
     * Si esta gravedad obliga a que la dirección vea el registro.
     *
     * Es la única razón por la que una incidencia saltaría a un rol que no
     * haya la creado.
     */
    public function requiereDireccion(): bool
    {
        return $this === self::Critica;
    }
}