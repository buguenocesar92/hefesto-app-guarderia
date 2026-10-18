<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Planes, matrículas y pagos.
 *
 * NO HAY MONTOS POR OMISIÓN
 * -------------------------
 * No hay plan básico de $180.000, ni mensualidad de $120.000, ni ningún monto
 * que este software decida. No se conoce lo que cobra cada guardería, y
 * escribir un precio sería inventar uno.
 *
 * El saldo NO es una columna
 * -------------------------
 * No hay campo `saldo` en `matriculas`. Se calcula como el precio del plan
 * menos la suma de los pagos no anulados. Un saldo guardado se desactualiza en
 * cuanto alguien registra un pago y no lo actualiza; un saldo calculado no
 * puede quedar viejo.
 *
 * `monto_clp` es un entero de pesos. Sin decimales, porque el monto se registra
 * como lo declara la persona y agregar precisión sería inventarla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planes', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->foreign('tenant_id')->references('id')->on('guarderias')->cascadeOnDelete();
            $tabla->string('nombre', 120);
            $tabla->integer('precio_clp');
            $tabla->integer('dias_cobro')->nullable();
            $tabla->string('descripcion', 500)->nullable();
            $tabla->boolean('activo')->default(true)->index();
            $tabla->timestampTz('creado_en')->useCurrent();

            $tabla->unique(['tenant_id', 'nombre'], 'unico_plan_nombre');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.planes
                ADD CONSTRAINT plan_precio_no_negativo
                CHECK (precio_clp >= 0);
        SQL);

        Schema::create('matriculas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->foreign('tenant_id')->references('id')->on('guarderias')->cascadeOnDelete();
            $tabla->uuid('nino_id');
            $tabla->foreign('nino_id')->references('id')->on('ninos')->cascadeOnDelete();
            $tabla->uuid('plan_id');
            $tabla->foreign('plan_id')->references('id')->on('planes')->restrictOnDelete();
            $tabla->date('inicia_en');
            $tabla->date('termina_en')->nullable();
            $tabla->boolean('activa')->default(true)->index();
            $tabla->uuid('registrada_por');
            $tabla->foreign('registrada_por')->references('id')->on('usuarios')->nullOnDelete();
            $tabla->timestampTz('creado_en')->useCurrent();

            // Un niño puede tener dos matrículas activas: cuando cambia de plan
            // a mitad de mes, la anterior queda cerrada y la nueva abierta. Sin
            // esto, cambiar de plan sería un UPDATE sobre la fila que ya tenía.
            $tabla->index(['tenant_id', 'nino_id', 'activa']);
        });

        Schema::create('pagos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->foreign('tenant_id')->references('id')->on('guarderias')->cascadeOnDelete();
            $tabla->uuid('matricula_id');
            $tabla->foreign('matricula_id')->references('id')->on('matriculas')->cascadeOnDelete();
            $tabla->integer('monto_clp');
            $tabla->date('pagado_en');
            $tabla->string('metodo', 40)->nullable();
            $tabla->string('comprobante', 120)->nullable();
            $tabla->string('estado', 24)->default('registrado')->index();
            $tabla->string('motivo_anulacion', 500)->nullable();
            $tabla->uuid('anulado_por')->nullable();
            $tabla->foreign('anulado_por')->references('id')->on('usuarios')->nullOnDelete();
            $tabla->timestampTz('anulado_en')->nullable();
            $tabla->uuid('registrado_por');
            $tabla->foreign('registrado_por')->references('id')->on('usuarios')->nullOnDelete();
            $tabla->timestampTz('creado_en')->useCurrent();

            $tabla->index(['tenant_id', 'matricula_id', 'estado']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.pagos
                ADD CONSTRAINT pago_monto_no_negativo
                CHECK (monto_clp > 0);
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON TABLE public.pagos IS
            'Un pago anulado conserva la fila, con motivo y con quien lo anulo. Borrar un pago deja el mismo hueco que borrar una autorizacion: el saldo de una familia deja de poder reconstruirse.';
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos');
        Schema::dropIfExists('matriculas');
        Schema::dropIfExists('planes');
    }
};