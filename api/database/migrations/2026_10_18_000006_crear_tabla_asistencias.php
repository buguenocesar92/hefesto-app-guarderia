<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asistencia diaria.
 *
 * UN DÍA, UNA FILA
 * ----------------
 * `unico_nino_fecha` es la razón por la que esta restricción se escribe a mano
 * en SQL y no como un `if` en el controlador: una validación de aplicación se
 * puede saltar con una consulta directa, y un doble clic en "registrar
 * ingreso" produce dos filas si la validación no está en la base.
 *
 * LA AUSENCIA EXIGE MOTIVO Y AVISO
 * -------------------------------
 * La diferencia entre "no vino y avisaron" y "no vino y nadie avisó" es la
 * diferencia entre una ausencia y una situación que hay que mirar hoy. El
 * `CHECK` obliga a que la segunda tenga su motivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistencias', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->foreign('tenant_id')->references('id')->on('guarderias')->cascadeOnDelete();
            $tabla->uuid('nino_id');
            $tabla->foreign('nino_id')->references('id')->on('ninos')->cascadeOnDelete();
            $tabla->date('fecha');
            $tabla->timestampTz('ingreso_en')->nullable();
            $tabla->timestampTz('egreso_en')->nullable();
            $tabla->string('estado', 24)->default('presente')->index();

            // Obligatorio sólo si el niño no vino. Es lo que obliga a que
            // "no llegó y nadie avisó" se escriba con un motivo y con la hora en
            // que se supo.
            $tabla->string('motivo_ausencia', 240)->nullable();
            $tabla->uuid('avisado_por')->nullable();
            $tabla->foreign('avisado_por')->references('id')->on('responsables')->nullOnDelete();
            $tabla->string('observacion', 500)->nullable();
            $tabla->uuid('registrado_por');
            $tabla->foreign('registrado_por')->references('id')->on('usuarios')->nullOnDelete();
            $tabla->timestampTz('creado_en')->useCurrent();

            $tabla->unique('nino_id', 'unico_nino_fecha');
            $tabla->index(['tenant_id', 'fecha', 'estado']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.asistencias
                ADD CONSTRAINT asistencia_ausente_exige_motivo
                CHECK (
                    estado <> 'ausente'
                    OR (
                        motivo_ausencia IS NOT NULL
                        AND length(btrim(motivo_ausencia)) > 0
                    )
                );
        SQL);

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.asistencias
                ADD CONSTRAINT asistencia_ingreso_requiere_hora
                CHECK (
                    estado = 'ausente'
                    OR ingreso_en IS NOT NULL
                );
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencias');
    }
};