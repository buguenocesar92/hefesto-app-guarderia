<?php

declare(strict_types=1);

namespace App\Services\Permisos;

use App\Enums\RolGuarderia;
use App\Models\Usuario;

/**
 * Traduce el rol de una persona a lo que puede hacer.
 *
 * POR QUÉ NO ESTÁ EN EL MODELO
 * ---------------------------
 * Porque el permiso de un usuario es el de su rol, y el de su rol no cambia
 * entre peticiones. Si estuviera en la sesión, dos personas con el mismo rol
 * podrían terminar con permisos distintos, y eso no se puede depurar.
 *
 * En cada petición se construye uno nuevo con el rol leído de la base. Es una
 * lectura de una fila, y a cambio queda una sola fuente de verdad.
 */
final class CapacidadesDeUsuario
{
    /** @var array<string, mixed> */
    private array $capacidades = [];

    private function __construct(private readonly Usuario $usuario) {}

    public static function de(Usuario $usuario): self
    {
        $instancia = new self($usuario);
        $rol = $usuario->rol;

        $capacidades = [
            // Identidad
            'guarderia_id' => $usuario->guarderia_id,
            'rol' => $rol->value,
            'etiqueta_rol' => $rol->etiqueta(),
            'es_direccion' => $rol === RolGuarderia::Direccion,

            // Ventanas de la aplicación
            've_ninos' => true,
            've_responsables' => true,
            'opera_puertas' => $rol->operaPuertas(),

            // Dinero
            've_pagos' => $rol->vePagos(),
            'administra_planes' => $rol === RolGuarderia::Direccion,

            // Lo sensible
            'abre_fichas_medicas' => $rol->abreFichasMedicas(),
            'acceso_ficha_medica' => $rol->accesoAFichaMedica(),

            // Registros
            'registra_incidencias' => true,
            've_bitacora' => $rol === RolGuarderia::Direccion,
            'administra_usuarios' => $rol === RolGuarderia::Direccion,
        ];

        $instancia->capacidades = $capacidades;

        return $instancia;
    }

    public function usuario(): Usuario
    {
        return $this->usuario;
    }

    public function rol(): RolGuarderia
    {
        return $this->usuario->rol;
    }

    public function puede(string $capacidad): bool
    {
        return (bool) ($this->capacidades[$capacidad] ?? false);
    }

    /**
     * Las capacidades para mandarlas a la interfaz.
     *
     * El frontend no decide permisos: los lee. Si una pantalla nueva necesita
     * saber si puede mostrar un botón, pregunta acá en lugar de duplicar la
     * lista de roles en TypeScript, que es donde se desincroniza.
     *
     * @return array<string, mixed>
     */
    public function aArreglo(): array
    {
        return $this->capacidades;
    }

    /**
     * Si esta persona puede abrir fichas médicas.
     *
     * Existe por legibilidad en las Policies. La decisión real la toma la
     * Policy de `FichaMedica`, que además comprueba la guardería.
     */
    public function abreFichasMedicas(): bool
    {
        return $this->puede('abre_fichas_medicas');
    }

    /**
     * Si esta persona ve el dinero del centro.
     */
    public function vePagos(): bool
    {
        return $this->puede('ve_pagos');
    }
}