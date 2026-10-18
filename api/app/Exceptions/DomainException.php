<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Error de negocio: lo que pasó no es un fallo del sistema.
 *
 * POR QUÉ ES UNA CLASE PROPIA Y NO UN `abort()`
 * ----------------------------------------------
 * Porque la diferencia entre "el sistema falló" y "el sistema hizo lo que
 * debía" importa, y el código HTTP es la única forma de que la interfaz la
 * note.
 *
 * Un `abort(422)` dentro de un controlador obliga a reconstruir el motivo
 * leyendo el controlador. Con esta excepción, el motivo viaja con el error y
 * `bootstrap/app.php` lo traduce a un 422 con un mensaje que una persona puede
 * leer en pantalla.
 *
 * El caso canónico es el retiro: alguien llega a buscar a un niño con una
 * autorización vencida. Eso NO es un error del sistema. Es el sistema
 * funcionando, y la respuesta tiene que ser clara.
 */
final class DomainException extends Exception
{
    /**
     * Error de negocio con un código propio, para que la interfaz pueda
     * reaccionar distinto a cada caso sin leer el mensaje.
     */
    public const RETIRO_SIN_AUTORIZACION = 'retiro_sin_autorizacion';

    public const RETIRO_AUTORIZACION_VENCIDA = 'retiro_autorizacion_vencida';

    public const RETIRO_AUTORIZACION_ANULADA = 'retiro_autorizacion_anulada';

    public const RETIRO_NI_NO_PRESENTE = 'retiro_ni_no_presente';

    public const RETIRO_DUPLICADO = 'retiro_duplicado';

    public const ASISTENCIA_YA_CERRADA = 'asistencia_ya_cerrada';

    public const AUTORIZACION_VIGENCIA_INVALIDA = 'autorizacion_vigencia_invalida';

    private function __construct(
        string $mensaje,
        public readonly string $codigo,
    ) {
        parent::__construct($mensaje);
    }

    public static function retiroSinAutorizacion(string $nombreResponsable, string $nombreNino): self
    {
        return new self(
            sprintf(
                '%s no está autorizado para retirar a %s. No hay ninguna autorización vigente a su nombre.',
                $nombreResponsable,
                $nombreNino,
            ),
            self::RETIRO_SIN_AUTORIZACION,
        );
    }

    public static function retiroConAutorizacionVencida(string $nombreResponsable, string $fechaVencida): self
    {
        return new self(
            sprintf(
                'La autorización de %s venció el %s y no fue renovada. No puede retirar al niño hasta que un adulto responsable renueve la autorización.',
                $nombreResponsable,
                $fechaVencida,
            ),
            self::RETIRO_AUTORIZACION_VENCIDA,
        );
    }

    public static function retiroConAutorizacionAnulada(string $nombreResponsable, string $motivo): self
    {
        return new self(
            sprintf(
                'La autorización de %s fue anulada: %s',
                $nombreResponsable,
                $motivo,
            ),
            self::RETIRO_AUTORIZACION_ANULADA,
        );
    }

    public static function retiroDeNinoNoPresente(string $nombreNino): self
    {
        return new self(
            sprintf('%s no tiene ingreso registrado hoy. No hay nada que retirar.', $nombreNino),
            self::RETIRO_NI_NO_PRESENTE,
        );
    }

    public static function retiroDuplicado(string $nombreNino): self
    {
        return new self(
            sprintf('%s ya tiene un retiro registrado hoy.', $nombreNino),
            self::RETIRO_DUPLICADO,
        );
    }

    public static function asistenciaYaCerrada(string $nombreNino): self
    {
        return new self(
            sprintf('La asistencia de %s de hoy ya tiene salida registrada.', $nombreNino),
            self::ASISTENCIA_YA_CERRADA,
        );
    }

    public static function vigenciaInvalida(): self
    {
        return new self(
            'La fecha de vencimiento no puede ser anterior a la fecha de inicio de la vigencia.',
            self::AUTORIZACION_VIGENCIA_INVALIDA,
        );
    }

    /**
     * El código, para mandarlo a la interfaz junto con el mensaje.
     *
     * @return array{mensaje: string, codigo: string}
     */
    public function aArreglo(): array
    {
        return [
            'mensaje' => $this->getMessage(),
            'codigo' => $this->codigo,
        ];
    }
}