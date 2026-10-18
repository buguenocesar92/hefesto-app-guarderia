<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de responsables, y la tabla que la une con los niños.
 *
 * POR QUÉ LA RELACIÓN ES MUCHOS A MUCHOS
 * --------------------------------------
 * Porque una misma persona es responsable de varios niños —hermanos, primos,
 * sobrinos— y cada uno tiene sus propias autorizaciones de retiro, con plazos
 * distintos. La hermana puede estar autorizada para uno y no para el otro, y el
 * registro de retiro tiene que poder distinguir esos dos casos.
 *
 * Con un campo `responsable_id` en `ninos`, esa situación no se puede
 * representar. Ver docs/02.
 *
 * `es_responsable_legal` sí es un campo, porque es un hecho del vínculo
 * familiar y no del permiso. La organización legal del menor no cambia según el
 * niño; lo que cambia es la autorización, y esa vive en
 * `autorizaciones_retiros`, con su fecha.
 *
 * `parentesco` es texto y no un enum. "Tía", "abuela" y "madre" no son un
 * conjunto cerrado, y forzar a que entren en una lista los hace mal escritos.
 * No es un campo con reglas: es un dato descriptivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('responsables', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->foreign('tenant_id')->references('id')->on('guarderias')->cascadeOnDelete();
            $tabla->string('nombres', 120);
            $tabla->string('apellidos', 120);
            $tabla->string('documento_tipo', 24)->default('rut');
            $tabla->string('documento_numero', 32)->nullable();
            $tabla->string('telefono', 40)->nullable();
            $tabla->string('correo', 160)->nullable();
            $tabla->string('direccion', 240)->nullable();
            $tabla->boolean('activo')->default(true)->index();
            $tabla->timestampTz('creado_en')->useCurrent();

            $tabla->index(['tenant_id', 'apellidos']);

            // La búsqueda en pantalla de retiro es por documento, no por
            // apellido: quien llega a la puerta dice "soy la madre, mi RUT es
            // este". Sin índice, esa búsqueda es un recorrido de tabla.
            $tabla->index(['tenant_id', 'documento_numero']);
        });

        Schema::create('responsable_nino', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->foreign('tenant_id')->references('id')->on('guarderias')->cascadeOnDelete();
            $tabla->uuid('nino_id');
            $tabla->foreign('nino_id')->references('id')->on('ninos')->cascadeOnDelete();
            $tabla->uuid('responsable_id');
            $tabla->foreign('responsable_id')->references('id')->on('responsables')->cascadeOnDelete();
            $tabla->string('parentesco', 60)->nullable();
            $tabla->boolean('es_responsable_legal')->default(false);
            $tabla->timestampTz('creado_en')->useCurrent();

            // Un vínculo una sola vez. Sin esto, la misma pareja queda
            // dos veces y la pantalla de responsables muestra duplicados.
            $tabla->unique(['nino_id', 'responsable_id']);
            $tabla->index(['tenant_id', 'nino_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('responsable_nino');
        Schema::dropIfExists('responsables');
    }
};