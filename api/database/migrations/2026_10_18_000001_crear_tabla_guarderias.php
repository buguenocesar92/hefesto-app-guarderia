<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de guarderías: el inquilino de esta instalación multi-tenant.
 *
 * POR QUÉ NO LLEVA ROW LEVEL SECURITY
 * -----------------------------------
 * Porque se consulta ANTES del ingreso, cuando no hay sesión y por lo tanto no
 * hay `app.guarderia_id` que poner. Con Row Level Security, esa consulta no
 * devolvería nada y no se podría ni siquiera mostrar el selector de guardería
 * en la pantalla de ingreso.
 *
 * Para que la excepción no se convierta en una fuga, la aplicación NO lee esta
 * tabla cruda antes del ingreso: lee `vw_guarderias_publicas`, que expone TRES
 * columnas. Ver `*_crear_vista_guarderias_publicas.php` y docs/03.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guarderias', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->string('nombre', 160);
            $tabla->string('slug', 80)->unique();
            $tabla->string('razon_social', 160)->nullable();
            $tabla->string('comuna', 80)->nullable();
            $tabla->string('direccion', 240)->nullable();
            $tabla->string('telefono', 40)->nullable();
            $tabla->string('correo', 160)->nullable();
            $tabla->boolean('activo')->default(true)->index();
            $tabla->timestampTz('creado_en')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE public.guarderias IS
            'Tabla de inquilinos. SIN Row Level Security a proposito: se consulta antes del ingreso, sin contexto de sesion. Antes del ingreso solo se lee la vista vw_guarderias_publicas, que expone id, nombre y slug de las activas.';
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('guarderias');
    }
};