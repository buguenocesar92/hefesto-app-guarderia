<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Guardar la ficha médica de un niño.
 *
 * POR QUÉ LOS LARGOS MÍNIMOS ESTÁN EN EL FormRequest Y NO EN EL CONTROLADOR
 * ------------------------------------------------------------------------
 * Porque son una regla de la forma del dato, y la forma del dato es lo que se
 * valida antes de que nada más importe. Los máximos salen de
 * `config/guarderia.php` para que no estén repetidos en tres archivos.
 *
 * LO QUE NO SE VALIDA
 * -------------------
 * No se valida el contenido clínico. El sistema no sabe si una alergia es
 * probable, si una dosis es la correcta ni si un medicamento se puede dar con
 * cierta comida. Eso lo escribe el adulto responsable y lo revisa quien tiene
 * permiso para verlo.
 *
 * Ver docs/06.
 */
class GuardarFichaMedicaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La autorización real la hace la Policy, que además mira el rol y la
        // guardería. Acá sólo se evita que alguien sin sesión escriba algo.
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>|string>
     */
    public function rules(): array
    {
        return [
            'alergias' => ['nullable', 'string', 'max:' . (int) config('guarderia.fichas_medicas.alergias_maximo_caracteres', 4000)],
            'alimentacion' => ['nullable', 'string', 'max:240'],
            'alimentos_prohibidos' => ['nullable', 'string', 'max:' . (int) config('guarderia.fichas_medicas.alergias_maximo_caracteres', 4000)],
            'medicacion' => ['nullable', 'string', 'max:' . (int) config('guarderia.fichas_medicas.medicacion_maximo_caracteres', 4000)],
            'plan_emergencia' => ['nullable', 'string', 'max:' . (int) config('guarderia.fichas_medicas.plan_emergencia_maximo_caracteres', 4000)],
            'contacto_emergencia_nombre' => ['nullable', 'string', 'max:160'],
            'contacto_emergencia_telefono' => ['nullable', 'string', 'max:40'],

            // Declaración del adulto responsable, NO permiso del personal. El
            // texto lo dice en el formulario de la interfaz.
            'autoriza_medicamento' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'alergias.max' => 'La descripción de alergias es demasiado larga.',
            'medicacion.max' => 'La indicación de medicación es demasiado larga.',
            'plan_emergencia.max' => 'El plan de emergencia es demasiado largo.',
            'alimentacion.max' => 'La indicación de alimentación es demasiado larga.',
            'autoriza_medicamento.boolean' => 'La autorización de medicación debe ser sí o no.',
        ];
    }
}