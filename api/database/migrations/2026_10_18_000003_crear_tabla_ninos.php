<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de niños.
 *
 * LO QUE NO ESTÁ ACÁ
 * ------------------
 * Las alergias, la alimentación y la medicación NO están en esta tabla. Están en
 * `fichas_medicas`, que es una tabla aparte y con Row Level Security aparte.
 *
 * La razón: esta tabla se lista en pantalla, se consulta desde el panel y la lee
 * el rol administrativo. Si las alergias estuvieran acá, la ficha médica dejaría
 * de estar protegida el día que alguien escribiera una consulta a `ninos` para
 * armar una lista. Ver docs/03.
 *
 * `rut` es texto y no un número: el RUT chileno tiene dígito verificador y
 * formato con guion, y no se validó contra el registro civil. Se guarda lo que la
 * persona declara. Ver docs/10.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ninos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->foreign('tenant_id')->references('id')->on('guarderias')->cascadeOnDelete();
            $tabla->string('nombres', 120);
            $tabla->string('apellidos', 120);
            $tabla->date('fecha_nacimiento');
            $tabla->string('sexo', 24)->nullable();
            $tabla->string('rut', 24)->nullable();
            $tabla->boolean('activo')->default(true)->index();
            $tabla->timestampTz('creado_en')->useCurrent();

            // Un niño se busca por apellido todo el día, en pantalla de retiro
            // y en pantalla de asistencia. Un índice por apellido vale más que
            // uno por nombre: el apellido es lo que se conoce.
            $tabla->index(['tenant_id', 'apellidos']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ninos');
    }
};