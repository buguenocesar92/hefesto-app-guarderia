<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Autorizaciones de retiro.
 *
 * ESTA TABLA ES LA QUE RESUELVE EL PROBLEMA CENTRAL DE LA APLICACIÓN
 * ---------------------------------------------------------------
 * `vigente_hasta` es la columna que hace que el sistema pueda decir "no". Sin
 * fecha de vencimiento, una vez autorizada una persona para retirar a un niño,
 * queda autorizada para siempre, y eso no se puede revertir con el software.
 *
 * `vigente_hasta` ADMITE NULO A PROPÓSITO: hay centros donde la autorización de
 * un progenitor legal no vence. Es una decisión del centro, y por eso la columna
 * es nullable y no tiene un valor inventado por omisión. El sistema avisa antes
 * de vencer SOLO las que tienen fecha. Ver docs/07.
 *
 * ANULAR NO ES ELIMINAR
 * ---------------------
 * Una autorización anulada conserva la fila con su motivo y con quién la anuló.
 * Una autorización que se borró no se puede citar después de una conversación con
 * la familia, y este es un registro de responsabilidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autorizaciones_retiros', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->foreign('tenant_id')->references('id')->on('guarderias')->cascadeOnDelete();
            $tabla->uuid('nino_id');
            $tabla->foreign('nino_id')->references('id')->on('ninos')->cascadeOnDelete();
            $tabla->uuid('responsable_id');
            $tabla->foreign('responsable_id')->references('id')->on('responsables')->cascadeOnDelete();

            $tabla->date('vigente_desde')->nullable();
            $tabla->date('vigente_hasta')->nullable();

            // El documento con que se identificó el adulto al firmar la
            // autorización. Es distinto del documento que hay hoy en
            // `responsables`: la autorización se firma una vez, y ese día vale.
            $tabla->string('documento_tipo', 24)->nullable();
            $tabla->string('documento_numero', 32)->nullable();

            $tabla->string('estado', 24)->default('vigente')->index();
            $tabla->text('motivo_anulacion')->nullable();
            $tabla->uuid('anulada_por')->nullable();
            $tabla->timestampTz('anulada_en')->nullable();

            $tabla->uuid('registrada_por');
            $tabla->foreign('registrada_por')->references('id')->on('usuarios')->nullOnDelete();
            $tabla->timestampTz('creado_en')->useCurrent();

            // La búsqueda del retiro: niño + responsable + las que están
            // vigentes. Es la consulta que se hace en la puerta, y se hace
            // siempre.
            $tabla->index(['tenant_id', 'nino_id', 'responsable_id']);

            // La búsqueda del aviso: las que vencen pronto. Sin fecha, no
            // vencen, así que no entran en el índice.
            $tabla->index(['tenant_id', 'vigente_hasta']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autorizaciones_retiros');
    }
};