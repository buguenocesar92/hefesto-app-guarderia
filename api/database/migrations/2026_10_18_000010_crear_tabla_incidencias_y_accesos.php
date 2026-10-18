<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Incidencias y bitácora de accesos.
 *
 * DOS TABLAS, UNA INTENCIÓN
 * ------------------------
 * `incidencias` es lo que pasó con un niño. `accesos` es quién abrió qué ficha
 * y cuándo. Son cosas distintas y por eso son tablas distintas: una incidencia
 * la escribe una educadora y la lee la dirección; un acceso lo escribe el
 * sistema y lo lee la dirección.
 *
 * LAS DOS NO SE BORRAN
 * --------------------
 * No hay ruta de borrado para ninguna de las dos, y no es una omisión que se
 * complete más adelante. Un registro que se puede borrar no sirve para
 * reconstruir nada. Ver docs/07.
 *
 * `accesos` ANOTA TAMBIÉN LO DENEGADO
 * ---------------------------------
 * `resultado` admite `permitido` y `denegado`. Un intento denegado es
 * información: es el caso en que alguien pidió algo que no le correspondía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidencias', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->foreign('tenant_id')->references('id')->on('guarderias')->cascadeOnDelete();
            $tabla->uuid('nino_id');
            $tabla->foreign('nino_id')->references('id')->on('ninos')->cascadeOnDelete();

            // Categoria en texto, no en enum: "conducta", "salud", "alimentacion"
            // y "otro" no son un conjunto cerrado, y la lista la arma el centro.
            $tabla->string('categoria', 60)->nullable()->index();
            $tabla->string('gravedad', 24)->default('menor')->index();
            $tabla->timestampTz('ocurrido_en');
            $tabla->text('descripcion');
            $tabla->text('accion_tomada');
            $tabla->uuid('informada_a')->nullable();
            $tabla->foreign('informada_a')->references('id')->on('responsables')->nullOnDelete();
            $tabla->boolean('requiere_seguimiento')->default(false)->index();
            $tabla->uuid('registrada_por');
            $tabla->foreign('registrada_por')->references('id')->on('usuarios')->nullOnDelete();
            $tabla->timestampTz('creado_en')->useCurrent();

            $tabla->index(['tenant_id', 'nino_id', 'ocurrido_en']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.incidencias
                ADD CONSTRAINT incidencia_gravedad_valida
                CHECK (gravedad IN ('menor', 'moderada', 'relevante', 'critica'));
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE public.incidencias IS
            'Registro de incidentes. No se borra. Una incidencia sin accion tomada deja registro del problema y no de la respuesta, y con la respuesta no se sostiene una conversacion con la familia.';
        SQL);

        Schema::create('accesos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->foreign('tenant_id')->references('id')->on('guarderias')->cascadeOnDelete();
            $tabla->uuid('usuario_id')->nullable();
            $tabla->foreign('usuario_id')->references('id')->on('usuarios')->nullOnDelete();

            // El rol queda COPIADO, no relacionado. La bitácora tiene que decir
            // el rol que tenía la persona al abrir la ficha, y no el rol que
            // tiene hoy: dentro de seis meses nadie se acuerda de que era
            // administrativa y hoy es directora.
            $tabla->string('rol', 32)->nullable();

            $tabla->string('recurso', 60)->index();
            $tabla->uuid('recurso_id')->nullable();
            $tabla->string('accion', 40);
            $tabla->string('resultado', 24)->index();
            $tabla->string('motivo', 240)->nullable();
            $tabla->string('ruta', 200)->nullable();
            $tabla->string('direccion_ip', 45)->nullable();
            $tabla->timestampTz('ocurrido_en')->useCurrent()->index();

            $tabla->index(['tenant_id', 'ocurrido_en']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.accesos
                ADD CONSTRAINT acceso_resultado_valido
                CHECK (resultado IN ('permitido', 'denegado'));
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE public.accesos IS
            'Bitacora de accesos. Anota lo permitido Y lo denegado. Su plazo de conservacion esta en null porque no se verifico ninguno: el sistema no purga nada. Ver docs/07.';
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('accesos');
        Schema::dropIfExists('incidencias');
    }
};