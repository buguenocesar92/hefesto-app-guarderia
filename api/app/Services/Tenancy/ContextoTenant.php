<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Guarderia;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;

/**
 * Fija la guardería de la petición en la sesión de PostgreSQL.
 *
 * EL MECANISMO, Y POR QUÉ ES UNA TRANSACCIÓN
 * -------------------------------------------
 * La Row Level Security de PostgreSQL lee el valor de `current_setting` en el
 * momento de cada consulta. `set_config(..., true)` —el `true` es el tercer
 * argumento— hace que ese valor tenga alcance de TRANSACCIÓN: vive lo que dura
 * la transacción y se descarta al terminar.
 *
 * El alcance de SESSION sería el otro error clásico: sobrevive al cierre de la
 * transacción, así que una petición que no fija su contexto deja el anterior
 * puesto, y la siguiente consulta de otra guardería lee filas ajenas. Con
 * `true` eso no puede pasar: si nadie fijó contexto, no hay contexto.
 *
 * Y al revés de lo que suele ocurrir, `set_config` no usa la sesión de PHP ni
 * sus conexiones persistentes: `set_config` habla con la sesión de PostgreSQL,
 * que es distinta. Por eso `web/` usa Redis para el estado en memoria y el
 * `DB_CONNECTION` sigue siendo `pgsql`.
 *
 * Ver docs/03.
 */
final class ContextoTenant
{
    /**
     * Abre una transacción con el contexto de guardería ya fijado.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $operacion
     * @return TResult
     */
    public function enTransaccionDe(string $guarderiaId, callable $operacion): mixed
    {
        if ($guarderiaId === '') {
            // Fallo cerrado: sin identificador de guardería no hay contexto que
            // fijar, y fijar un contexto vacío daría acceso a cero filas con un
            // error de conexión en vez de un 404.
            throw new RuntimeException('No se puede abrir una transacción sin identificador de guardería.');
        }

        return DB::transaction(function () use ($guarderiaId, $operacion): mixed {
            // El contexto se fija DENTRO de la transacción y con alcance de
            // transacción. Al hacer rollback, el valor desaparece junto con lo
            // que se hizo.
            DB::select('SELECT set_config(\'app.guarderia_id\', ?, true)', [$guarderiaId]);

            // El segundo booleano desactiva la caché de la función en la
            // consulta. Aquí la función no tiene argumentos y solo lee el
            // contexto; dejarla cacheada sería correcto, pero desactivarla hace
            // que un fallo de contexto se note en la primera consulta y no en la
            // quinta. Ver docs/03, sección "Verificación".
            return $operacion();
        }, 3);
    }

    /**
     * Si hay un contexto fijado ahora mismo.
     *
     * Lo usa el verificador de aislamiento para comprobar que una consulta sin
     * contexto NO lee filas, que es la propiedad que de verdad importa.
     */
    public function hayContexto(): bool
    {
        $contexto = DB::selectOne('SELECT current_setting(\'app.guarderia_id\', true) AS valor');

        return $contexto !== null && $contexto->valor !== null && $contexto->valor !== '';
    }

    /**
     * El identificador de guardería del contexto actual.
     */
    public function guarderiaActual(): ?string
    {
        $contexto = DB::selectOne('SELECT current_setting(\'app.guarderia_id\', true) AS valor');

        if ($contexto === null || $contexto->valor === null || $contexto->valor === '') {
            return null;
        }

        return (string) $contexto->valor;
    }

    /**
     * Traduce un error de PostgreSQL a algo que se pueda mostrar.
     *
     * PostgreSQL avisa cuando una Row Level Security no deja pasar una fila
     * con el código 42501, y ese mensaje en crudo no le dice nada a una
     * educadora. Acá se traduce a un 404 con un mensaje que explica qué pasó.
     */
    public function mensajeDeViolacionDePolitica(PDOException $error): ?string
    {
        if (! str_contains($error->getMessage(), '42501')) {
            return null;
        }

        return 'No encontrado.';
    }

    /**
     * Guarda una guardería recién creada como contexto, para que sus propias
     * migraciones y su primer ingreso funcionen.
     *
     * Es el único caso en que el contexto se fija sin venir de una sesión: es
     * la instalación, no una petición de negocio.
     */
    public function fijarParaInstalacion(Guarderia $guarderia): void
    {
        DB::statement('SELECT set_config(\'app.guarderia_id\', ?, false)', [$guarderia->id]);
    }
}