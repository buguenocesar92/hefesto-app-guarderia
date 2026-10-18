<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vista pública de guarderías: la excepción consciente de la Row Level Security.
 *
 * `guarderias` NO lleva Row Level Security, porque se consulta antes del
 * ingreso, cuando todavía no hay sesión y por lo tanto no hay
 * `app.guarderia_id` que poner.
 *
 * ANTES DEL INGRESO LA APLICACIÓN NO LEE `guarderias` CRUDO
 * -------------------------------------------------------
 * Lee esta vista, que expone TRES columnas (`id`, `nombre`, `slug`) y solo de
 * las guarderías activas. No expone RUT, razón social, dirección, correo ni
 * teléfono.
 *
 * Y SE USA DE VERDAD
 * ------------------
 * La consume `Publico\GuarderiasPublicasController`, que es el que arma el
 * selector de la pantalla de ingreso, y la comprueban dos pruebas:
 *
 *   - `tests/Feature/Aislamiento/VistaPublicaSeUsaTest.php` falla si alguien
 *     cambia el controlador para leer `guarderias`.
 *   - `guarderia:verificar-aislamiento` falla si la vista deja de exponer
 *     exactamente esas tres columnas.
 *
 * En aplicaciones anteriores de Hefesto se creó esta vista y nunca se consultó,
 * mientras la documentación afirmaba lo contrario. Acá el uso está en el código y
 * hay una prueba que lo vigila. Ver docs/03.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE VIEW public.vw_guarderias_publicas AS
            SELECT
                id,
                nombre,
                slug
            FROM public.guarderias
            WHERE activo = true
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON VIEW public.vw_guarderias_publicas IS
            'Lo unico que se puede leer de guarderias antes del login: id, nombre y slug de las activas. No expone razon social, direccion, correo ni telefono.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS public.vw_guarderias_publicas');
    }
};