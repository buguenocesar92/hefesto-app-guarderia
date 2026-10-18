<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlaGuarderia;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La ficha de salud de un niño: alergias, alimentación y medicación.
 *
 * EL DATO MÁS SENSIBLE DE ESTA APLICACIÓN
 * --------------------------------------
 * Esto es dato de salud de un menor. Es más sensible que un nombre y un
 * apellido, porque identifica a alguien por su cuerpo y por una condición que
 * la persona no eligió tener.
 *
 * Y por eso está en su propia tabla, y no en `ninos`:
 *
 *   - Tiene su propia Row Level Security, que además se activa SOLO cuando la
 *     ruta lleva el middleware `medica.auditada`.
 *   - Se abre por su propia ruta, nunca aparece en un listado de niños.
 *   - Cada lectura y cada escritura queda anotada en `accesos`, con el rol de
 *     quien la hizo y el motivo.
 *   - El rol administrativo NO entra: no ve alergias ni medicación. No es que
 *     "no tenga permiso para", es que su trabajo no lo incluye.
 *
 * LO QUE ESTE MODELO NO HACE
 * -------------------------
 * No valida los datos clínicos. No dice si una alergia es probable, ni si una
 * dosis es la correcta, ni si un medicamento se puede dar con cierta comida.
 * Eso lo escribe y lo revisa el adulto responsable y la persona con
 * autorización para hacerlo. El software no opina.
 *
 * Ver docs/03 y docs/07.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $nino_id
 * @property string|null $alergias
 * @property string|null $alimentacion
 * @property string|null $alimentos_prohibidos
 * @property string|null $medicacion
 * @property string|null $plan_emergencia
 * @property string|null $contacto_emergencia_nombre
 * @property string|null $contacto_emergencia_telefono
 * @property bool $autoriza_medicamento
 * @property string $actualizado_por
 * @property \Illuminate\Support\Carbon $actualizado_en
 * @property \Illuminate\Support\Carbon $creado_en
 */
class FichaMedica extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlaGuarderia;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'fichas_medicas';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'nino_id',
        'alergias',
        'alimentacion',
        'alimentos_prohibidos',
        'medicacion',
        'plan_emergencia',
        'contacto_emergencia_nombre',
        'contacto_emergencia_telefono',
        'autoriza_medicamento',
        'actualizado_por',
        'actualizado_en',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'autoriza_medicamento' => 'boolean',
            'actualizado_en' => 'datetime',
            'creado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Nino, $this>
     */
    public function nino(): BelongsTo
    {
        return $this->belongsTo(Nino::class, 'nino_id');
    }

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function ultimoEditor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'actualizado_por');
    }

    /**
     * Si esta ficha tiene algo que una educadora necesita saber antes de servir
     * el almuerzo.
     *
     * Es lo que la pantalla de grupo muestra, y lo que el panel marca en rojo.
     */
    public function tieneRestricciones(): bool
    {
        return $this->alergias !== null && trim($this->alergias) !== ''
            || $this->alimentos_prohibidos !== null && trim($this->alimentos_prohibidos) !== '';
    }

    /**
     * Las restricciones como texto para las pantallas resumidas.
     *
     * La pantalla de retiro NO usa esto: la pantalla de retiro muestra al niño,
     * al responsable y la hora. La ficha médica se abre aparte, y en grande.
     */
    public function resumenDeRestricciones(): string
    {
        $partes = [];

        if ($this->alergias !== null && trim($this->alergias) !== '') {
            $partes[] = 'Alergias: ' . $this->alergias;
        }

        if ($this->alimentos_prohibidos !== null && trim($this->alimentos_prohibidos) !== '') {
            $partes[] = 'Alimentos prohibidos: ' . $this->alimentos_prohibidos;
        }

        if ($this->medicacion !== null && trim($this->medicacion) !== '') {
            $partes[] = 'Medicación: ' . $this->medicacion;
        }

        return $partes === [] ? 'Sin restricciones registradas.' : implode("\n", $partes);
    }
}