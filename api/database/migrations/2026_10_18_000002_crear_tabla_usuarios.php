<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de usuarios de la guardería.
 *
 * EL ROL ES UNA COLUMNA, NO UNA CONSULTA A AUTHENTIK EN CADA PETICIÓN
 * ---------------------------------------------------------------
 * `rol` se guarda. Si se leyera del equipo de Authentik en cada petición,
 * sacar a alguien de un equipo en Authentik lo desactivaría al instante, y a
 * nadie se le ocurre ir a revisar la base después de eso.
 *
 * El precio de esa decisión es que hay que mantener los dos lugares, y por eso
 * existe `rol_actualizado_en`: dice cuándo se revisó el rol a mano. Ver docs/03.
 *
 * `sub` es el identificador del sujeto en Authentik. No es una llave foránea:
 * la fila se crea al primer ingreso, y en ese momento el resto del mundo en
 * Authentik no es asunto nuestro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->foreign('tenant_id')->references('id')->on('guarderias')->cascadeOnDelete();
            $tabla->string('sub', 160)->index();
            $tabla->string('correo', 160);
            $tabla->string('nombres', 120);
            $tabla->string('apellidos', 120);
            $tabla->string('rol', 32)->index();
            $tabla->boolean('activo')->default(true);
            $tabla->timestampTz('ultimo_ingreso_en')->nullable();
            $tabla->timestampTz('rol_actualizado_en')->useCurrent();
            $tabla->timestampTz('creado_en')->useCurrent();

            // Una persona es una sola persona en una guardería. La unicidad es
            // por (guardería, correo), no global: la misma dirección de correo
            // puede ser de alguien en dos centros distintos.
            $tabla->unique(['tenant_id', 'correo']);
            $tabla->unique(['tenant_id', 'sub']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};