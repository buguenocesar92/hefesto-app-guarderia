import type { Capacidades, Sesion, Usuario } from '~/tipos/api'
import { llamarApi } from '~/../server/utils/api'

/**
 * La sesión de la persona que está usando el sistema.
 *
 * QUÉ HAY ACÁ Y QUÉ NO
 * --------------------
 * Acá está QUIÉN es y QUÉ PUEDE. No está ninguna decisión.
 *
 * `puede(nombre)` existe para que una pantalla no ofrezca un botón que va a
 * dar 404. La decisión real la toma la Policy en la API, y esa respuesta es la
 * que manda: si el navegador cree que puede y la API no, gana la API.
 *
 * POR QUÉ NO SE GUARDA EN UN ALMACÉN DE ESTADO GLOBAL
 * ---------------------------------------------------
 * Porque la sesión cambia: alguien entra, alguien sale, y un rol cambia en
 * Authentik. Un estado global que se carga una vez y nunca se refresca acaba
 * mostrando menús que ya no corresponden, y eso se convierte en "el sistema a
 * veces deja ver cosas".
 *
 * `recargar()` existe para eso.
 */
export const useSesion = () => {
    const usuario = useState<Usuario | null>('sesion:usuario', () => null)
    const capacidades = useState<Capacidades | null>('sesion:capacidades', () => null)
    const cargado = useState<boolean>('sesion:cargado', () => false)

    /**
     * Trae la sesión del servidor.
     *
     * No falla si no hay sesión: devuelve `null` en `usuario`, que es la forma
     * de decir "no hay nadie ingressado". Una pantalla de ingreso no debería
     * romperse porque el backend respondió 401.
     */
    const cargar = async (forzar = false): Promise<void> => {
        if (cargado.value && !forzar) {
            return
        }

        try {
            const respuesta = await llamarApi<Sesion>('GET', 'auth/yo')

            usuario.value = respuesta.usuario
            capacidades.value = respuesta.capacidades
        } catch (error) {
            // 401 es lo normal cuando no hay sesión. No es un error que haya que
            // mostrar: es el estado de partida de la pantalla de ingreso.
            usuario.value = null
            capacidades.value = null
        } finally {
            cargado.value = true
        }
    }

    /**
     * Cierra la sesión y manda a la pantalla de ingreso.
     */
    const salir = async (): Promise<void> => {
        try {
            await llamarApi('POST', 'auth/salida')
        } finally {
            // Aunque el cierre falle en el servidor, la sesión local se limpia:
            // dejar el menú pintado a alguien que ya salió es peor que un error.
            usuario.value = null
            capacidades.value = null
            cargado.value = true

            await navigateTo('/entrar')
        }
    }

    /**
     * Si la persona puede hacer algo, según su rol.
     *
     * Para PINTAR la interfaz. No para decidir permiso.
     */
    const puede = (nombre: keyof Capacidades): boolean => {
        return capacidades.value?.[nombre] === true
    }

    const nombreCompleto = (): string => {
        if (usuario.value === null) {
            return ''
        }

        return `${usuario.value.nombres} ${usuario.value.apellidos}`.trim()
    }

    return {
        usuario,
        capacidades,
        cargado,
        cargar,
        salir,
        puede,
        nombreCompleto
    }
}