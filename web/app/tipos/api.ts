/**
 * Los tipos de la API, del lado del navegador.
 *
 * POR QUÉ ESTOS TIPOS Y NO `any`
 * ------------------------------
 * Porque una pantalla de retiro que cree que `permite_retiro` es `true` cuando
 * es `false` es un defecto de seguridad con tipografía bonita.
 *
 * Y el orden importa: `permite_retiro` y `dias_para_vencer` son OBLIGATORIOS en
 * `EstadoDeAutorizacion`. Si llegaran `undefined`, la pantalla dibujaría un
 * "AUTORIZADO" en verde sobre una autorización vencida, porque el `if` de un
 * valor indefinido cae en la rama del `else`.
 *
 * Ver docs/09.
 */

/** Los tres roles del centro. Escalonados: cada uno PUEDE y NO PUEDE. */
export type RolGuarderia = 'educadora' | 'administrativo' | 'direccion'

/**
 * Lo que la persona puede hacer, según su rol.
 *
 * La INTERFAZ NO DECIDE PERMISOS: los lee. La decisión real la toma la Policy,
 * en la API. Acá sólo se usa para no pintar una pantalla que va a dar 404.
 */
export interface Capacidades {
    guarderia_id: string
    rol: RolGuarderia
    etiqueta_rol: string
    es_direccion: boolean

    ve_ninos: boolean
    ve_responsables: boolean
    opera_puertas: boolean

    ve_pagos: boolean
    administra_planes: boolean

    abre_fichas_medicas: boolean
    /** Por qué no se puede ver, cuando no se puede. Se muestra, no se esconde. */
    acceso_ficha_medica: string

    registra_incidencias: boolean
    ve_bitacora: boolean
    administra_usuarios: boolean
}

export interface Usuario {
    nombres: string
    apellidos: string
    rol: RolGuarderia
    etiqueta_rol?: string
}

export interface Sesion {
    usuario: Usuario
    capacidades: Capacidades
}

/** La guardería del selector de ingreso. Sólo tres columnas: eso expone la vista. */
export interface GuarderiaPublica {
    id: string
    nombre: string
    slug: string
}

/** Un niño. NUNCA trae la ficha médica: sólo si existe. */
export interface Nino {
    id: string
    nombres: string
    apellidos: string
    nombre: string
    fecha_nacimiento: string | null
    edad: number
    sexo: string | null
    rut: string | null
    activo: boolean
    /** Un sí o un no. Saber que existe una ficha no es poder abrirla. */
    tiene_ficha_medica: boolean
    responsables?: ResponsableEnNino[]
}

export interface ResponsableEnNino {
    id: string
    nombre: string
    parentesco: string | null
    es_responsable_legal: boolean
    documento: string
}

export interface AutorizacionDeResponsable {
    nino_id: string
    estado: EstadoAutorizacion
    vigente_hasta: string | null
    dias_para_vencer: number | null
    permite_retiro: boolean
}

export interface Responsable {
    id: string
    nombres: string
    apellidos: string
    nombre: string
    documento_tipo: string
    documento_etiqueta: string
    documento_numero: string | null
    documento: string
    telefono: string | null
    correo: string | null
    activo: boolean
    ninos?: Array<{ id: string; nombre: string; parentesco: string | null; es_responsable_legal: boolean }>
    autorizaciones?: AutorizacionDeResponsable[]
}

/**
 * El estado de una autorización HOY.
 *
 * `no_autorizado` NO es un estado de la autorización: es la ausencia de
 * autorización. Y la pantalla tiene que distinguirlo de `vencida`, porque son
 * conversaciones distintas con la familia.
 */
export type EstadoAutorizacion = 'vigente' | 'vencida' | 'anulada' | 'no_autorizado'

/**
 * Lo que la pantalla de retiro necesita para decidir el COLOR.
 *
 * `titulo` y `detalle` vienen calculados del servidor y en MAYÚSCULA el título,
 * porque se muestran en grande. Escribirlo en la API y no en el navegador
 * significa que la misma regla produce el mismo texto en todas las pantallas.
 */
export interface EstadoDeAutorizacion {
    estado: EstadoAutorizacion
    permite_retiro: boolean
    vigente_hasta: string | null
    dias_para_vencer: number | null
    por_vencer: boolean
    titulo: string
    detalle: string
}

/** Un responsable en la pantalla de retiro, con su autorización de hoy. */
export interface ResponsableParaRetiro {
    id: string
    nombre: string
    parentesco: string | null
    es_responsable_legal: boolean
    documento: string
    autorizacion_id: string | null
    estado: EstadoAutorizacion
    vigente_hasta: string | null
    dias_para_vencer: number | null
    permite_retiro: boolean
}

export interface NinoParaRetiro {
    id: string
    nombre: string
    fecha_nacimiento: string | null
    edad: number
}

/** Lo que devuelve `GET /ninos/{nino}/retiro`. */
export interface PantallaDeRetiro {
    nino: NinoParaRetiro
    responsables: ResponsableParaRetiro[]
    /** La hora del servidor. Es la que cuenta, no la del reloj de la pantalla. */
    hora: string
}

/** El registro de un retiro. Se escriben LOS DOS resultados. */
export interface Retiro {
    id: string
    nino_id: string
    nino?: { id: string; nombre: string }
    responsable_nombre: string
    responsable_documento: string
    autorizacion_id: string | null
    asistencia_id: string | null
    ocurrido_en: string | null
    /** La hora suelta, para mostrarla grande en el registro. */
    hora: string | null
    resultado: 'autorizado' | 'rechazado'
    autorizado: boolean
    motivo_rechazo: string | null
    observacion: string | null
    operador: string | null
    creado_en: string | null
}

export interface Autorizacion {
    id: string
    nino_id: string
    nino?: { id: string; nombre: string }
    responsable_id: string
    responsable?: { id: string; nombre: string; documento: string }
    vigente_desde: string | null
    vigente_hasta: string | null
    estado: EstadoAutorizacion
    estado_etiqueta: string
    permite_retiro: boolean
    dias_para_vencer: number | null
    por_vencer: boolean
    documento_tipo: string | null
    documento_numero: string | null
    motivo_anulacion: string | null
    anulada_en: string | null
    creado_en: string | null
}

export type EstadoAsistencia = 'presente' | 'salida_temporal' | 'retirado' | 'ausente'

export interface Asistencia {
    id: string
    nino_id: string
    fecha: string | null
    estado: EstadoAsistencia
    estado_etiqueta: string
    ingreso_en: string | null
    egreso_en: string | null
    estuvo_en_el_centro: boolean
    motivo_ausencia: string | null
    aviso?: { id: string; nombre: string } | null
    /** "No llegó y nadie avisó". Es lo que el panel pone adelante. */
    sin_aviso: boolean
    observacion: string | null
    /** Cerrada sin registro de retiro: un hueco que hay que reconstruir a mano. */
    sin_registro_de_retiro: boolean
}

export interface ResumenAsistencia {
    total: number
    presentes: number
    salidas_temporales: number
    retirados: number
    ausentes: number
    sin_aviso: number
}

export type GravedadIncidencia = 'menor' | 'moderada' | 'relevante' | 'critica'

export interface Incidencia {
    id: string
    nino_id: string
    nino?: { id: string; nombre: string }
    categoria: string | null
    gravedad: GravedadIncidencia
    gravedad_etiqueta: string
    requiere_direccion: boolean
    ocurrido_en: string | null
    descripcion: string
    /** SIEMPRE viaja. Una incidencia sin acción no dice qué se hizo. */
    accion_tomada: string
    informada_a?: { id: string; nombre: string } | null
    requiere_seguimiento: boolean
    autora: string | null
    creado_en: string | null
}

/** La ficha médica. Dato de salud de un menor: la pantalla más restringida. */
export interface FichaMedica {
    id: string
    nino_id: string
    nino?: { id: string; nombre: string }
    alergias: string | null
    alimentacion: string | null
    alimentos_prohibidos: string | null
    medicacion: string | null
    plan_emergencia: string | null
    contacto_emergencia_nombre: string | null
    contacto_emergencia_telefono: string | null
    /** Declaración del ADULTO RESPONSABLE, no un permiso del personal. */
    autoriza_medicamento: boolean
    tiene_restricciones: boolean
    actualizado_en: string | null
}

export interface Plan {
    id: string
    nombre: string
    precio_clp: number
}

export interface Matricula {
    id: string
    nino_id: string
    plan_id: string
    plan?: Plan | null
    inicia_en: string | null
    termina_en: string | null
    activa: boolean
    total_pagado: number
    /** CALCULADO. No hay columna de saldo en la base. */
    saldo_clp: number
    saldo_legible: string
    creado_en: string | null
}

export type EstadoPago = 'registrado' | 'anulado'

export interface Pago {
    id: string
    matricula_id: string
    monto_clp: number
    monto_legible: string
    pagado_en: string | null
    metodo: string | null
    comprobante: string | null
    estado: EstadoPago
    estado_etiqueta: string
    suma_al_saldo: boolean
    motivo_anulacion: string | null
    anulado_en: string | null
    creado_en: string | null
}

export interface Panel {
    fecha: string
    capacidades: Capacidades
    hoy: {
        ninos_en_el_centro: number
        presentes: number
        salidas_temporales: number
        retirados: number
        ausentes: number
        ausentes_sin_aviso: number
        ausentes_sin_aviso_detalle: Array<{ nino_id: string; nombre: string }>
    }
    pendientes: {
        autorizaciones_por_vencer: number
        dias_anticipacion_del_aviso: number
        incidencias_con_seguimiento: number
        retiros_rechazados_hoy: number
    }
}