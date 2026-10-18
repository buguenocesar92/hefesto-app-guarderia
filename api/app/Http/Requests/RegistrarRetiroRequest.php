<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Registrar un retiro.
 *
 * QUÉ SE EXIGE Y QUÉ NO
 * ---------------------
 * Se exige el responsable. NO se exige la autorización: la autorización no la
 * elige quien registra el retiro, la tiene o no la tiene el responsable.
 *
 * Si el campo `autorizacion_id` se manda vacío, el servicio busca la autorización
 * vigente de ese responsable para ese niño. Si no hay ninguna, escribe el
 * registro del rechazo y responde 422.
 *
 * ES LA RAZÓN POR LA QUE ESTA PANTALLA NO ES UN FORMULARIO LIBRE
 * ---------------------------------------------------------
 * Un formulario con un campo "autorizado: sí/no" sería el error de diseño de
 * esta aplicación. El sistema no pregunta si el retiro está autorizado: lo
 * comprueba. Ver `App\Services\Retiros\ServicioRetiro`.
 */
class RegistrarRetiroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>|string>
     */
    public function rules(): array
    {
        return [
            'responsable_id' => ['required', 'string', 'uuid'],
            'autorizacion_id' => ['nullable', 'string', 'uuid'],
            'observacion' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'responsable_id.required' => 'Elija quién viene a retirar al niño.',
            'responsable_id.uuid' => 'El responsable seleccionado no existe.',
            'observacion.max' => 'La observación es demasiado larga.',
        ];
    }
}