import type { Capacidades, Sesion } from '~/tipos/api'

/**
 * Cliente de la API, para las pantallas del navegador.
 *
 * TODAS LAS LLAMADAS PASAN POR AQUÍ
 * ---------------------------------
 * Para que el manejo de sesión y de errores esté en un solo lugar, y para que
 * ninguna pantalla escriba a mano una ruta y se desincronice.
 *
 * POR QUÉ LAS RUTAS EMPIEZAN POR `/api/`
 * --------------------------------------
 * El navegador nunca habla con la API de Laravel. Habla con el servidor de
 * Nuxt, en el mismo origen, y ese reenvía la petición en
 * `server/api/[...ruta].ts`.
 *
 * La cookie de sesión es de mismo origen, así que viaja sola, y el token CSRF
 * viaja en la cabecera que Laravel espera. No hay ningún token en el código del
 * navegador. Ver docs/01.
 *
 * QUÉ SIGNIFICA UN 404 AQUÍ
 * -------------------------
 * En esta aplicación un 404 casi siempre significa "no tiene permiso", no "no
 * existe". Ver `App\Policies\Concerns\RespondeNoEncontrado`.
 *
 * Por eso el error que se propaga trae `sinPermiso`, para que la pantalla pueda
 * explicar el motivo en vez de mostrar un vacío que parece una falla.
 *
 * Ver docs/03 y docs/09.
 */
export interface ErrorDeApi extends Error {
    estado: number
    mensaje: string
    codigo: string | null
    errores: Record<string, string>
    sinPermiso: boolean
    sinSesion: boolean
}

export async function llamarApi<T>(
    metodo: 'GET' | 'POST',
    ruta: string,
    cuerpo?: unknown
): Promise<T> {
    const opciones: RequestInit = {
        method: metodo,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json'
        }
    }

    if (cuerpo !== undefined) {
        opciones.headers = {
            ...opciones.headers,
            'Content-Type': 'application/json'
        }
        opciones.body = JSON.stringify(cuerpo)
    }

    const respuesta = await fetch(`/api/${ruta.replace(/^\/+/, '')}`, opciones)

    const texto = await respuesta.text()

    let datos: Record<string, unknown> = {}

    if (texto !== '') {
        try {
            datos = JSON.parse(texto) as Record<string, unknown>
        } catch {
            datos = { mensaje: texto }
        }
    }

    if (!respuesta.ok) {
        throw construirError(respuesta.status, datos)
    }

    return datos as T
}

function construirError(estado: number, datos: Record<string, unknown>): ErrorDeApi {
    const mensaje = typeof datos.mensaje === 'string'
        ? datos.mensaje
        : 'Ocurrió un problema. Intente de nuevo.'

    const error = new Error(mensaje) as ErrorDeApi

    error.estado = estado
    error.mensaje = mensaje
    error.codigo = typeof datos.codigo === 'string' ? datos.codigo : null
    error.errores = (datos.errores ?? {}) as Record<string, string>

    error.sinPermiso = estado === 404
    error.sinSesion = estado === 401

    return error
}

/**
 * Convierte cualquier error en algo que se pueda mostrar sin romperse.
 *
 * El mensaje del servidor se muestra tal cual, porque en esta aplicación los
 * mensajes son para personas: "La autorización venció el 12 de marzo" no es un
 * error técnico, es una explicación.
 */
export function mensajeLegible(error: unknown): string {
    if (error instanceof Error) {
        return error.message
    }

    return 'Ocurrió un problema inesperado.'
}

/**
 * Una fecha de la API, en el formato que se usa en Chile.
 *
 * La fecha viene en ISO, `2026-10-18`. Se parte en trozos A PROPÓSITO: pasar
 * `2026-10-18` a `new Date()` lo interpreta como medianoche en hora local, y en
 * un navegador con huso horario al oeste de UTC eso puede mostrar el día
 * anterior. Partir la cadena evita ese desplazamiento entero.
 */
export function formatearFecha(fecha: string | null): string {
    if (fecha === null || fecha === '') {
        return ''
    }

    const soloFecha = fecha.split('T')[0] ?? ''
    const partes = soloFecha.split('-')

    if (partes.length !== 3) {
        return soloFecha
    }

    return `${partes[2]}-${partes[1]}-${partes[0]}`
}

/**
 * Un número de pesos, con el separador de miles que se usa en Chile.
 */
export function formatearPesos(monto: number): string {
    return `$${monto.toLocaleString('es-CL', { maximumFractionDigits: 0 })}`
}