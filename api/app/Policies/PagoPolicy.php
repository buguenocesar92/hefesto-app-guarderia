<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Pago;
use App\Models\Usuario;
use App\Policies\Concerns\RespondeNoEncontrado;

/**
 * Permisos sobre pagos y planes.
 *
 * QUIÉN VE EL DINERO
 * ------------------
 * `Administrativo` y `Direccion`. El administrativo cobra, y para cobrar tiene
 * que ver lo que se debe.
 *
 * `Educadora` NO ve pagos. Y acá no se trata de proteger el dato: es que la
 * educadora no necesita ver cuánto paga una familia para servir el almuerzo.
 * Ocultarle el saldo no agrega nada.
 *
 * ANULAR UN PAGO ES DE DIRECCIÓN
 * -----------------------------
 * Registrar un pago es trabajo de todos los que operan. Quitar un pago del
 * saldo es otra cosa: deshace un hecho económico, y eso lo revisa quien responde
 * por el centro.
 */
class PagoPolicy
{
    use RespondeNoEncontrado;

    public function ver(Usuario $usuario, Pago $pago): \Illuminate\Auth\Access\Response
    {
        if ($pago->tenant_id !== $usuario->tenant_id) {
            return $this->noEncontrado();
        }

        if (! $usuario->rol->vePagos()) {
            return $this->noEncontrado(
                'El rol ' . $usuario->rol->etiqueta() . ' no ve los pagos del centro.',
            );
        }

        return \Illuminate\Auth\Access\Response::allow();
    }

    /**
     * Registrar un pago.
     */
    public function crear(Usuario $usuario, string $tenantId): \Illuminate\Auth\Access\Response
    {
        if ($tenantId !== $usuario->tenant_id) {
            return $this->noEncontrado();
        }

        if (! $usuario->rol->vePagos()) {
            return $this->noEncontrado(
                'El rol ' . $usuario->rol->etiqueta() . ' no registra pagos del centro.',
            );
        }

        return \Illuminate\Auth\Access\Response::allow();
    }

    /**
     * Anular un pago.
     */
    public function anular(Usuario $usuario, Pago $pago): \Illuminate\Auth\Access\Response
    {
        if ($pago->tenant_id !== $usuario->tenant_id) {
            return $this->noEncontrado();
        }

        if ($usuario->rol !== \App\Enums\RolGuarderia::Direccion) {
            return $this->noEncontrado(
                'Anular un pago es de la dirección del centro. El pago se conserva con su anulación anotada.',
            );
        }

        return \Illuminate\Auth\Access\Response::allow();
    }
}