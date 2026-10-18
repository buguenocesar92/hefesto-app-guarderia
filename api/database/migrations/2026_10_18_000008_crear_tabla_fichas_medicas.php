<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fichas médicas: alergias, alimentación y medicación.
 *
 * LA TABLA MÁS PROTEGIDA DE TODA LA APLICACIÓN
 * -------------------------------------------
 * Es dato de salud de un menor. Está en su propia tabla, con su propia Row
 * Level Security, y no aparece en ninguna lista de niños.
 *
 * `autoriza_medicamento` NO es un permiso del personal: es la declaración del
 * adulto responsable de si se puede administrar un medicamento en el centro.
 *
 * Eso no es lo mismo que el permiso del sistema, y la diferencia importa: el
 * campo dice qué decidió la familia; la Policy dice quién puede LEERLO. Que un
 * campo sea `true` no le da permiso a nadie para ver nada.
 *
 * Ver docs/03 y docs/07.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fichas_medicas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->foreign('tenant_id')->references('id')->on('guarderias')->cascadeOnDelete();
            $tabla->uuid('nino_id');
            $tabla->foreign('nino_id')->references('id')->on('ninos')->cascadeOnDelete();

            $tabla->text('alergias')->nullable();
            $tabla->string('alimentacion', 240)->nullable();
            $tabla->text('alimentos_prohibidos')->nullable();
            $tabla->text('medicacion')->nullable();
            $tabla->text('plan_emergencia')->nullable();
            $tabla->string('contacto_emergencia_nombre', 160)->nullable();
            $tabla->string('contacto_emergencia_telefono', 40)->nullable();

            // Declaración del adulto responsable, NO permiso del sistema.
            $tabla->boolean('autoriza_medicamento')->default(false);

            $tabla->uuid('actualizado_por');
            $tabla->foreign('actualizado_por')->references('id')->on('usuarios')->nullOnDelete();
            $tabla->timestampTz('actualizado_en')->useCurrent();
            $tabla->timestampTz('creado_en')->useCurrent();

            // Una ficha por niño. La unicidad impide que existan dos fichas
            // Médicas para el mismo niño, que es como aparecen las versiones
            // que se contradicen.
            $tabla->unique('nino_id', 'unico_nino');
            $tabla->index(['tenant_id', 'autoriza_medicamento']);
        });

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE public.fichas_medicas IS
            'DATO DE SALUD DE UN MENOR. Acceso escalonado por rol: el administrativo no entra. Cada lectura y cada escritura queda anotada en accesos. Ver docs/03 y docs/07.';
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON COLUMN public.fichas_medicas.autoriza_medicamento IS
            'Declaracion del adulto responsable sobre si se puede administrar medicamento en el centro. NO es un permiso del personal: no habilita a nadie a leer esta ficha.';
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('fichas_medicas');
    }
};