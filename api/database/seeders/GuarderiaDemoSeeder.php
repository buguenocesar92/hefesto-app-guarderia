<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\EstadoPago;
use App\Enums\EstadoAsistencia;
use App\Models\Asistencia;
use App\Models\AutorizacionRetiro;
use App\Models\Guarderia;
use App\Models\Matricula;
use App\Models\Nino;
use App\Models\Pago;
use App\Models\Plan;
use App\Models\Responsable;
use App\Models\Usuario;
use App\Enums\RolGuarderia;
use Illuminate\Database\Seeder;

/**
 * Datos de demostración para una instalación nueva.
 *
 * QUÉ HACE Y QUÉ NO
 * -----------------
 * Crea una guardería, una persona por rol, tres niños, sus responsables, una
 * autorización vigente y una vencida, y una asistencia de hoy.
 *
 * LO QUE NO HACE, Y ES LO IMPORTANTE
 * ----------------------------------
 * No crea montos de mensualidad. El precio lo carga el centro, y esta
 * aplicación no tiene ni un monto escrito por omisión: poner $180.000 en un
 * seeder y llamarlo "plan básico" sería inventar una tarifa.
 *
 * Por eso el plan se crea con precio 0 y con una descripción que lo dice. Un
 * centro que corra esto tiene que cargar sus precios antes de cobrar nada.
 *
 * PARA QUÉ SIRVE
 * --------------
 * Para probar la pantalla de retiro sin esperar a que haya niños. Y para que
 * quien recibe la instalación vea, en el primer minuto, cómo se ve una
 * autorización vencida marcada en grande.
 *
 * Ver docs/04 y docs/06.
 */
class GuarderiaDemoSeeder extends Seeder
{
    public function run(): void
    {
        $guarderia = Guarderia::create([
            'nombre' => 'Guardería de demostración',
            'slug' => 'demostracion',
            'comuna' => 'Providencia',
            'activo' => true,
        ]);

        $this->command?->info('Guardería creada: ' . $guarderia->nombre . ' (' . $guarderia->slug . ')');

        foreach ([
            'Educadora' => [RolGuarderia::Educadora, 'Camila', 'Rojas'],
            'Administrativo' => [RolGuarderia::Administrativo, 'Jorge', 'Muñoz'],
            'Dirección' => [RolGuarderia::Direccion, 'Patricia', 'Soto'],
        ] as $etiqueta => [$rol, $nombre, $apellido]) {
            Usuario::create([
                'tenant_id' => $guarderia->id,
                'sub' => (string) \Illuminate\Support\Str::uuid(),
                'correo' => \Illuminate\Support\Str::slug($nombre . '.' . $apellido, '.') . '@ejemplo.local',
                'nombres' => $nombre,
                'apellidos' => $apellido,
                'rol' => $rol,
                'activo' => true,
                'rol_actualizado_en' => now(),
            ]);
        }

        $direccion = Usuario::query()->where('tenant_id', $guarderia->id)->where('rol', RolGuarderia::Direccion->value)->firstOrFail();

        // Un plan con precio 0 a propósito: el centro carga sus precios.
        $plan = Plan::create([
            'tenant_id' => $guarderia->id,
            'nombre' => 'Plan sin precio (cárguelo)',
            'precio_clp' => 0,
            'descripcion' => 'Plan de ejemplo con precio cero. Esta aplicación no trae tarifas: el precio lo define el centro.',
            'activo' => true,
        ]);

        $ninos = [];

        foreach ([
            ['Matías', 'Alarcón', 'Fabián', 'Bernal', true],
            ['Sofía', 'Carrasco', 'Elena', 'Carrasco', true],
            ['Lucas', 'Vera', 'Rodrigo', 'Vera', false],
        ] as [$nombre, $apellido, $responsableNombre, $responsableApellido, $conAutorizacionVigente]) {
            $nino = Nino::create([
                'tenant_id' => $guarderia->id,
                'nombres' => $nombre,
                'apellidos' => $apellido,
                'fecha_nacimiento' => now()->subYears(4)->toDateString(),
                'sexo' => 'masculino',
                'activo' => true,
            ]);

            $responsable = Responsable::create([
                'tenant_id' => $guarderia->id,
                'nombres' => $responsableNombre,
                'apellidos' => $responsableApellido,
                'documento_tipo' => \App\Enums\TipoDocumento::Rut,
                'documento_numero' => '12.345.678-5',
                'telefono' => '+56912345678',
                'activo' => true,
            ]);

            $nino->responsables()->attach($responsable->id, [
                'parentesco' => $apellido === $responsableApellido ? 'Padre' : 'Tío',
                'es_responsable_legal' => $apellido === $responsableApellido,
            ]);

            AutorizacionRetiro::create([
                'tenant_id' => $guarderia->id,
                'nino_id' => $nino->id,
                'responsable_id' => $responsable->id,
                'vigente_desde' => $conAutorizacionVigente ? now()->subMonth()->toDateString() : now()->subYear()->toDateString(),
                // El último tiene una autorización VENCIDA: es el caso que la
                // pantalla de retiro tiene que marcar en grande, y conviene
                // tenerlo a mano desde el primer minuto.
                'vigente_hasta' => $conAutorizacionVigente ? now()->addMonths(6)->toDateString() : now()->subMonth()->toDateString(),
                'documento_tipo' => \App\Enums\TipoDocumento::Rut,
                'documento_numero' => '12.345.678-5',
                'estado' => \App\Enums\EstadoAutorizacion::Vigente,
                'registrada_por' => $direccion->id,
            ]);

            Asistencia::create([
                'tenant_id' => $guarderia->id,
                'nino_id' => $nino->id,
                'fecha' => now()->toDateString(),
                'ingreso_en' => now()->setTime(8, 30),
                'egreso_en' => null,
                'estado' => EstadoAsistencia::Presente,
                'registrado_por' => $direccion->id,
            ]);

            // Matrícula creada, SIN pagos a propósito.
            //
            // No se inventan pagos: el plan tiene precio cero, y la restricción
            // `pago_monto_no_negativo` exige un monto mayor que cero. Cuando el
            // centro cargue sus precios, ahí se registran los pagos reales.
            Matricula::create([
                'tenant_id' => $guarderia->id,
                'nino_id' => $nino->id,
                'plan_id' => $plan->id,
                'inicia_en' => now()->startOfMonth()->toDateString(),
                'termina_en' => null,
                'activa' => true,
                'registrada_por' => $direccion->id,
            ]);

            $ninos[] = $nino;
        }

        $this->command?->newLine();
        $this->command?->info('Datos de demostración listos.');
        $this->command?->line('Tres niños, cada uno con su responsable y su autorización.');
        $this->command?->line('El último tiene una autorización VENCIDA: ábrala en la pantalla de retiro.');
        $this->command?->newLine();
        $this->command?->warn('Los usuarios de ejemplo tienen un `sub` inventado: no pueden ingresar.');
        $this->command?->line('Las personas reales se crean en su PRIMER INGRESO, leyendo su equipo de Authentik.');
        $this->command?->line('Verifique el aislamiento antes de usarla: php artisan guarderia:verificar-aislamiento');
    }
}