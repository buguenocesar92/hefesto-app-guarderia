<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de retiros: quién retiró a un niño, a qué hora y con qué
 * autorización.
 *
 * ESTA TABLA ESCRIBE LOS DOS RESULTADOS
 * ------------------------------------
 * `resultado` admite `autorizado` y `rechazado`, y se escriben LOS DOS.
 *
 * El rechazo se registra porque el caso raro es el que más importa: si alguien
 * llega a la puerta sin autorización, dentro de tres meses nadie se acuerda. Lo
 * que permite reconstruirlo es esta fila.
 *
 * POR QUÉ SE COPIAN EL NOMBRE Y EL DOCUMENTO
 * ------------------------------------------
 * `responsable_nombre_texto` y `responsable_documento_texto` congelan lo que se
 * sabía al momento del retiro. No es duplicación sin propósito: la ficha del
 * responsable puede cambiar, y el registro de lo que pasó a las cuatro de la
 * tarde de ese día no puede cambiar con ella.
 *
 * Un registro que cambia cuando cambian los datos de la persona no sirve para
 * reconstruir nada. Cuando `responsable_id` no es nulo, el texto congelado
 * sigue siendo el del momento del hecho.
 *
 * `responsable_id` es NULL cuando el responsable no está en el sistema. En ese
 * caso el nombre y el documento vienen del texto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retiros', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->foreign('tenant_id')->references('id')->on('guarderias')->cascadeOnDelete();
            $tabla->uuid('nino_id');
            $tabla->foreign('nino_id')->references('id')->on('ninos')->cascadeOnDelete();

            // NULL cuando quien llega no tiene ficha en el sistema.
            $tabla->uuid('responsable_id')->nullable();
            $tabla->foreign('responsable_id')->references('id')->on('responsables')->nullOnDelete();

            // Texto congelado al momento del retiro. Sobrevive al borrado o al
            // cambio del responsable.
            $tabla->string('responsable_nombre_texto', 240)->nullable();
            $tabla->string('responsable_documento_texto', 80)->nullable();

            // NULL cuando el retiro fue rechazado: no hay autorización que lo
            // ampare.
            $tabla->uuid('autorizacion_retiro_id')->nullable();
            $tabla->foreign('autorizacion_retiro_id')->references('id')->on('autorizaciones_retiros')->nullOnDelete();
            $tabla->uuid('asistencia_id')->nullable();
            $tabla->foreign('asistencia_id')->references('id')->on('asistencias')->nullOnDelete();

            $tabla->timestampTz('ocurrido_en');
            $tabla->string('documento_tipo', 24)->nullable();
            $tabla->string('documento_numero', 32)->nullable();
            $tabla->string('resultado', 24)->index();
            $tabla->text('motivo_rechazo')->nullable();
            $tabla->string('observacion', 500)->nullable();

            // NULL cuando el intento no llegó a identificarse con nadie: alguien
            // que entró a la puerta sin pasar por el sistema. El rechazo sigue
            // registrado igual.
            $tabla->uuid('registrado_por')->nullable();
            $tabla->foreign('registrado_por')->references('id')->on('usuarios')->nullOnDelete();
            $tabla->timestampTz('creado_en')->useCurrent();

            $tabla->index(['tenant_id', 'nino_id', 'ocurrido_en']);
        });

        // Un resultado es uno de los dos. No hay un tercer valor para
        // "quedamos a medias". La restricción va en SQL y no en el modelo,
        // porque un registro de retiro es un hecho: no puede quedar a medias.
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.retiros
                ADD CONSTRAINT retiro_resultado_valido
                CHECK (resultado IN ('autorizado', 'rechazado'));
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON COLUMN public.retiros.responsable_nombre_texto IS
            'Nombre congelado al momento del retiro. Un registro de responsabilidad no puede cambiar despues porque haya cambiado la ficha del responsable.';
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('retiros');
    }
};