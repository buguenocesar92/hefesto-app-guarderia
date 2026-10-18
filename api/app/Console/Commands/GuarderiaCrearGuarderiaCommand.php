<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Guarderia;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Da de alta una guardería en una instalación nueva.
 *
 * POR QUÉ ES UN COMANDO Y NO UN SEEDER
 * -----------------------------------
 * Porque la guardería es un dato del cliente, no un dato de prueba. El seeder
 * sirve para tests; esto sirve para instalar.
 *
 * LO QUE HACE, EN ORDEN
 * ---------------------
 * 1. Crea la fila en `guarderias`. Esa tabla no lleva Row Level Security, así
 *    que no necesita contexto.
 * 2. Fija el contexto de esa guardería, para que las siguientes operaciones
 *    corran con el aislamiento puesto.
 *
 * LO QUE NO HACE
 * --------------
 * No crea usuarios. Los usuarios se crean en el PRIMER INGRESO, leyendo el
 * equipo de Authentik de la persona.
 *
 * Y no crea planes con montos. Los precios los carga el centro: esta aplicación
 * no tiene ni un monto escrito por omisión. Ver docs/05.
 *
 * Ver docs/04 y docs/06.
 */
class GuarderiaCrearGuarderiaCommand extends Command
{
    protected $signature = 'guarderia:crear
                            {--nombre= : Nombre de la guardería.}
                            {--slug= : Identificador corto y único, en minúsculas y con guiones.}
                            {--comuna= : Comuna, si se conoce.}';

    protected $description = 'Crea una guardería en una instalación nueva.';

    public function handle(ContextoTenant $contexto): int
    {
        $nombre = $this->option('nombre');
        $slug = $this->option('slug');

        if (! is_string($nombre) || $nombre === '') {
            $this->error('Falta --nombre.');

            return self::FAILURE;
        }

        if (! is_string($slug) || $slug === '' || ! preg_match('/^[a-z0-9-]+$/', $slug)) {
            $this->error('Falta --slug, o no es válido. Debe ser sólo minúsculas, números y guiones.');

            return self::FAILURE;
        }

        if (Guarderia::query()->where('slug', $slug)->exists()) {
            $this->error('Ya existe una guardería con el identificador ' . $slug . '.');

            return self::FAILURE;
        }

        $guarderia = Guarderia::create([
            'nombre' => $nombre,
            'slug' => $slug,
            'comuna' => $this->option('comuna'),
            'activo' => true,
        ]);

        $contexto->fijarParaInstalacion($guarderia);

        $this->info('Guardería creada: ' . $guarderia->nombre . ' (' . $guarderia->slug . ').');
        $this->line('Ahora aparece en el selector de la pantalla de ingreso, porque')
            ->line('`vw_guarderias_publicas` la expone al estar activa.');

        $this->newLine();
        $this->warn('NO HAY USUARIOS TODAVÍA.');
        $this->line('Las personas se crean en su primer ingreso, leyendo su equipo de Authentik.');
        $this->line('Antes de recibirlas, cree los equipos: ' . config('authentik.grupo_por_guarderia') . '.');
        $this->newLine();
        $this->warn('Falta verificar el aislamiento:');
        $this->line('  php artisan guarderia:verificar-aislamiento');

        return self::SUCCESS;
    }
}