<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlaGuarderia;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La bitácora: quién abrió qué ficha y en qué momento.
 *
 * QUÉ SE ANOTA, Y QUÉ NO
 * ----------------------
 * Se anotan los accesos que importan por su sensibilidad: abrir o guardar una
 * ficha médica, y ver el registro de retiros. En la ruta se anota lo mismo, con
 * `resultado` `permitido` o `denegado`.
 *
 * UN ACCESO DENEGADO TAMBIÉN SE ANOTA
 * ---------------------------------
 * Porque un intento denegado es información: es el caso en que alguien pidió
 * algo que no le correspondía. Si sólo se anotara lo permitido, el registro
 * sería la lista de las cosas que el sistema dejó pasar, y no diría nada sobre
 * lo que intentó hacer alguien.
 *
 * PERO EL REGISTRO NO ES LA PROTECCIÓN
 * ------------------------------------
 * La bitácora sirve para reconstruir. La que impide abrir la ficha es la Row
 * Level Security más la Policy. Si alguien encuentra un modo de abrir una ficha
 * sin permiso, eso va a quedar anotado acá; y eso es un problema del que hay que
 * enterarse mirando el registro, no de que haya pasado inadvertido.
 *
 * LA RETENCIÓN DE ESTA TABLA ESTÁ EN `NULL`
 * ------------------------------------------
 * No se verificó un plazo de conservación para la bitácora de accesos. El campo
 * queda en `null` y el sistema no purga nada. Ver docs/07.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $usuario_id
 * @property string|null $rol
 * @property string $recurso
 * @property string|null $recurso_id
 * @property string $accion
 * @property string $resultado
 * @property string|null $motivo
 * @property string|null $ruta
 * @property string|null $direccion_ip
 * @property \Illuminate\Support\Carbon $ocurrido_en
 */
class Acceso extends Model
{
    use HasFactory;
    use HasUuids;
    use PerteneceAlaGuarderia;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'accesos';

    protected $fillable = [
        'tenant_id',
        'usuario_id',
        'rol',
        'recurso',
        'recurso_id',
        'accion',
        'resultado',
        'motivo',
        'ruta',
        'direccion_ip',
        'ocurrido_en',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ocurrido_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}