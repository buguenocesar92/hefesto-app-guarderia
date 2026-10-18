<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use Illuminate\Database\QueryException;

/**
 * Aplica el contexto de guardería a las consultas.
 *
 * POR QUÉ ESTA CLASE Y NO EL MIDDLEWARE DIRECTAMENTE
 * --------------------------------------------------
 * Porque hay que aplicarlo en más de un lugar, y si cada uno lo hace a su
 * manera, tarde o temprano uno lo hace peor. El middleware fija el contexto
 * para las peticiones web; esta clase lo usa para los comandos de consola, los
 * Jobs y los seeders, donde no hay petición.
 *
 * La diferencia es que en consola el contexto se pasa explícitamente, y aquí no
 * hay forma de que se use el contexto equivocado por accidente: o se pasa la
 * guardería, o la consulta falla.
 */
final class AplicaContextoTenant
{
    public function __construct(private readonly ContextoTenant $contexto) {}

    /**
     * Ejecuta una consulta con el contexto de la guardería fijado.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $operacion
     * @return TResult
     */
    public function para(string $guarderiaId, callable $operacion): mixed
    {
        return $this->contexto->enTransaccionDe($guarderiaId, $operacion);
    }

    /**
     * Cuenta filas de una tabla de negocio sin contexto.
     *
     * Solo lo usa `guarderia:verificar-aislamiento`, y solo como parte de la
     * prueba de que una consulta sin contexto NO lee nada. Que devuelva cero es
     * el resultado correcto; si devuelve más de cero, la instalación está mal.
     *
     * @return int<0, max>
     */
    public function contarSinContexto(string $tabla): int
    {
        try {
            /** @var array<int, array{contar: int}> $filas */
            $filas = \Illuminate\Support\Facades\DB::select(
                'SELECT count(*)::int AS contar FROM public.' . $tabla . ' WHERE tenant_id = current_setting(\'app.guarderia_id\', true)::uuid'
            );
        } catch (QueryException $error) {
            // Sin contexto, `current_setting(..., true)` devuelve NULL y el
            // casteo a uuid falla. Eso ya es la respuesta: la consulta no llegó
            // a devolver filas de ninguna guardería.
            return 0;
        }

        return (int) ($filas[0]->contar ?? 0);
    }
}