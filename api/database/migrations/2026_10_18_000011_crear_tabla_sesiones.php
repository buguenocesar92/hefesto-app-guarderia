<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesiones web.
 *
 * EL CONTROLADOR ES `database`, NO `file`
 * --------------------------------------
 * Una sesión en un archivo dentro del contenedor se pierde cuando el contenedor
 * se reinicia, y en esta aplicación eso significaría cerrar la sesión de una
 * educadora a mitad del ingreso de un niño.
 *
 * `user_id` es texto y no una llave foránea, a propósito: el usuario de esta
 * tabla es un sujeto de Authentik y no necesariamente tiene fila en `usuarios`
 * todavía. En la práctica sí la tiene —se crea en el ingreso— pero la sesión se
 * escribe antes de resolver la guardería, y una llave foránea en ese punto
 * crearía una dependencia de orden que no hace falta.
 *
 * Esta tabla tiene Row Level Security como todas las de negocio. Ver docs/03.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesiones', function (Blueprint $tabla): void {
            $tabla->string('id', 40)->primary();
            $tabla->uuid('tenant_id')->nullable();
            $tabla->foreign('tenant_id')->references('id')->on('guarderias')->cascadeOnDelete();
            $tabla->string('usuario_sub', 160)->nullable();
            $tabla->text('payload');
            $tabla->integer('ultimo_uso')->index();
            $tabla->timestampTz('creado_en')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesiones');
    }
};