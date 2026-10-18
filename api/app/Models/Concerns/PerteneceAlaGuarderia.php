<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Services\Tenancy\ContextoTenant;
use Illuminate\Database\Eloquent\Builder;

/**
 * Marca un modelo como perteneciente a una guardería.
 *
 * EL MODELO NO ES EL QUE PROTEGE
 * ------------------------------
 * Este concern filtra por `tenant_id` para que la aplicación no lea filas de
 * otra guardería. Eso es comodidad y evita errores tontos. NO es la protección.
 *
 * La protección de verdad son dos capas que no dependen de que alguien se
 * acuerde de usar este concern:
 *
 *   1. La fila tiene `tenant_id`, con llave foránea a `guarderias`.
 *   2. La tabla tiene Row Level Security con `USING` y `WITH CHECK`, y la
 *      tabla es dueña del esquema (`FORCE ROW LEVEL SECURITY`), de modo que
 *      también el dueño de la tabla queda sujeto a la política.
 *
 * Si alguien escribe un `SELECT` sin filtrar y la sesión no tiene contexto, la
 * base devuelve cero filas. El concern evita tener que acordarse; la Row Level
 * Security no deja olvidarse.
 *
 * Ver docs/03.
 */
trait PerteneceAlaGuarderia
{
    /**
     * Filtra por la guardería del contexto.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $consulta
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    public function scopeDeLaGuarderia(Builder $consulta): Builder
    {
        $contexto = app(ContextoTenant::class);
        $guarderiaId = $contexto->guarderiaActual();

        if ($guarderiaId === null) {
            // Sin contexto no se filtra por "nada": se filtra por un
            // identificador que no existe en ninguna tabla. Es fallar cerrado.
            // Devolver "todas las filas" acá sería un agujero con una línea de
            // código de largo.
            return $consulta->whereRaw('1 = 0');
        }

        return $consulta->where($consulta->getModel()->getTable() . '.tenant_id', $guarderiaId);
    }

    /**
     * El nombre corto de la persona o del niño.
     *
     * Los modelos de esta aplicación tienen dos nombres: `nombres` y
     * `apellidos`, porque en Chile el niño se llama por los dos y "nombre" a
     * secas es ambiguo.
     */
    protected function nombreCompleto(): string
    {
        return trim(
            $this->getAttribute('nombres') . ' ' . $this->getAttribute('apellidos'),
        );
    }
}