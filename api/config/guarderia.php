<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Reglas de Guarderia
|------------------------------------------------------------------------------
|
| Lo que la aplicacion necesita saber del centro y que no cabe en el codigo.
| Cada clave esta justificada al lado. Ver docs/05.
|
*/

$enteroONulo = static function (?string $valor): ?int {
    if ($valor === null) {
        return null;
    }

    $valor = trim($valor);

    return $valor === '' ? null : (int) $valor;
};

return [

    /*
    |----------------------------------------------------------------------
    | Autorizaciones de retiro
    |----------------------------------------------------------------------
    |
    | ESTA ES LA PARTE MÁS DELICADA DE ESTA CONFIGURACIÓN. LÉALA ANTES DE
    | CAMBIAR NADA.
    |
    | Una autorización de retiro dice QUIÉN PUEDE LLEVARSE a un niño. En una
    | guardería esa es la respuesta a la pregunta más grave que se le puede
    | hacer a un sistema así: "¿viene alguien autorizado a buscar a este
    | niño?".
    |
    | Y por eso NO hay ningún valor por omisión para la vigencia. Ni una
    | "duración habitual", ni "un año", ni "lo que dijera la normativa". Un
    | plazo escrito sin fuente es un plazo inventado, y esta aplicación no
    | inventa plazos legales.
    |
    | Lo que sí hay es el AVISO. Una autorización tiene fecha de vencimiento y
    | el sistema avisa ANTES de que expire. Con qué antelación se avisa es una
    | decisión de operación del centro, no una obligación legal verificada, y
    | por eso vive en configuración y no en el código.
    |
    | Ver docs/03 y docs/07.
    |
    */
    'autorizaciones' => [
        // Días de antelación con que se avisa que una autorización está por
        // vencer. Es una ayuda de operación, NO un plazo legal: el sistema
        // avisa porque es útil, no porque se haya verificado que deba.
        'dias_aviso_vencimiento' => (int) env('GUARDERIA_DIAS_AVISO_VENCIMIENTO', 15),

        // Máximo de días que puede lasting una autorización en una sola línea.
        // También es una ayuda de captura, no un límite legal.
        'vigencia_maxima_dias' => (int) env('GUARDERIA_VIGENCIA_MAXIMA_DIAS', 730),
    ],

    /*
    |----------------------------------------------------------------------
    | FICHAS MÉDICAS
    |----------------------------------------------------------------------
    |
    | Son el dato MÁS SENSIBLE de esta aplicación: son datos de salud de un
    | menor. Su acceso está escalonado por rol y NO hay campo booleano que lo
    | controle: el permiso sale del rol. Ver docs/03 y docs/07.
    |
    | Los límites de abajo son de captura de texto, no criterios clínicos.
    | El sistema no propone acción:, porciones ni tiempos de ayuno: eso lo
    | escribe el adulto responsable y lo revisa quien tiene permiso para
    | verlo.
    |
    */
    'fichas_medicas' => [
        // Caracteres máximos del detalle de alergias.
        'alergias_maximo_caracteres' => 4000,

        // Caracteres máximos de la indicación de medicación.
        'medicacion_maximo_caracteres' => 4000,

        // Caracteres máximos del plan de emergencia.
        'plan_emergencia_maximo_caracteres' => 4000,
    ],

    /*
    |----------------------------------------------------------------------
    | INCIDENCIAS
    |----------------------------------------------------------------------
    |
    | Una incidencia NO SE BORRA. Un registro de incidencias es lo que sostiene
    | una conversación difícil con la familia months después, y un registro
    | que se puede borrar no sostiene nada.
    |
    | En esta versión no hay ruta de borrado, y eso no es una omisión que se
    | complete más adelante. Ver docs/07.
    |
    */
    'incidencias' => [
        // Mínimo de caracteres de la descripción. Nadie escribe "se portó mal"
        // y tres meses después se sabe qué pasó.
        'descripcion_minimo_caracteres' => 20,

        // Mínimo de caracteres de la acción tomada. Una incidencia sin acción
        // deja registro del problema y no de la respuesta.
        'accion_minimo_caracteres' => 10,
    ],

    /*
    |----------------------------------------------------------------------
    | ASISTENCIA
    |----------------------------------------------------------------------
    |
    | La jornada no tiene una hora fija por omisión: cada centro tiene la
    | suya. Lo que sí se declara es cuánto tiempo después del ingreso se
    | considera que un niño "no llegó y nadie avisó", que es la situación que
    | el sistema tiene que hacer visible.
    |
    */
    'asistencia' => [
        // Minutos desde la hora en que empieza la jornada para considerar que
        // un niño que no registró ingreso y del que nadie avisó está en
        // ausencia. Es una regla de trabajo del centro, no una norma.
        'minutos_sin_aviso_para_ausencia' => (int) env('GUARDERIA_MINUTOS_SIN_AVISO', 60),
    ],

    /*
    |----------------------------------------------------------------------
    | CONSERVACIÓN: DEJAR EN VACÍO HASTA QUE EL CLIENTE LO DECIDA
    |----------------------------------------------------------------------
    |
    | NO HAY VALOR POR OMISIÓN, Y NO ES UN OLVIDO.
    |
    | No se pudo verificar en el entorno donde se escribió esta aplicación qué
    | plazo de conservación corresponde a la ficha de un menor en un centro
    | infantil, ni cuántos años se guardan los registros de asistencia o de
    | retiros. Peor aún: aunque se verificara, no hay un plazo único que sirva
    | para todos los datos de un centro.
    |
    | Vacío significa "no hay plazo configurado", y el sistema NO borra nada por
    | su cuenta. La razón de que sea así está en docs/07.
    | No complete estos valores con un número que le parezca razonable.
    |
    | GUARDERIA_RETENCION_FICHAS_MEDICAS_DIAS=
    | GUARDERIA_RETENCION_ASISTENCIAS_DIAS=
    | GUARDERIA_RETENCION_RETIROS_DIAS=
    | GUARDERIA_RETENCION_ACCESOS_DIAS=
    GUARDERIA_AVISAR_ANTES_DE_PURGAR=false

    */
    'conservacion' => [
        // Días que se conservan las fichas médicas. En null, no se purga nada y
        // queda como decisión pendiente del cliente.
        'fichas_medicas_dias' => $enteroONulo(env('GUARDERIA_RETENCION_FICHAS_MEDICAS_DIAS')),

        // Días que se conservan los registros de asistencia.
        'asistencias_dias' => $enteroONulo(env('GUARDERIA_RETENCION_ASISTENCIAS_DIAS')),

        // Días que se conservan los registros de retiro. Es el registro de
        //responsabilidad: en null no se purga nada.
        'retiros_dias' => $enteroONulo(env('GUARDERIA_RETENCION_RETIROS_DIAS')),

        // Días que se conservan los registros de acceso.
        'accesos_dias' => $enteroONulo(env('GUARDERIA_RETENCION_ACCESOS_DIAS')),

        // Si la aplicación avisa que hay algo por purgar. En false, no avisa: el
        // sistema nunca borra ficha de un menor por su cuenta.
        'avisar_antes_de_purgar' => (bool) env('GUARDERIA_AVISAR_ANTES_DE_PURGAR', false),
    ],

    /*
    |----------------------------------------------------------------------
    | Zona horaria por omisión
    |----------------------------------------------------------------------
    |
    | `guarderias` NO tiene zona horaria: es un dato de infraestructura y todas
    | las instalaciones de Hefesto corren en America/Santiago. Si alguna vez hay
    | una instalación fuera, es una migración, no una variable.
    |
    */
    'zona_horaria' => env('APP_TIMEZONE', 'America/Santiago'),

];