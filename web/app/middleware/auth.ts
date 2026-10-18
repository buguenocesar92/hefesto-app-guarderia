/**
 * Guardia de las pantallas que necesitan sesión.
 *
 * QUE HACE Y QUE NO HACE
 * ----------------------
 * Redirige a la pantalla de ingreso cuando no hay sesión. Eso es todo.
 *
 * NO es una medida de seguridad. Una pantalla sin sesión a la que se le pueda
 * pedir un dato sensible sería un agujero, y por eso el permiso de verdad se
 * comprueba en la API: este middleware corre en el navegador, y el navegador es
 * del lado del cliente.
 *
 * La razón de que exista igual es la experiencia: una pantalla vacía con el
 * menú y sin datos es peor que una pantalla de ingreso clara. Ver docs/03.
 */
export default defineNuxtRouteMiddleware(async (to) => {
    // La propia pantalla de ingreso no se protege: sería un bucle.
    if (to.path === '/entrar') {
        return
    }

    const { usuario, cargar } = useSesion()

    // En el servidor, el estado ya viene resuelto desde la carga inicial.
    if (import.meta.server && usuario.value !== null) {
        return
    }

    await cargar()

    if (usuario.value === null) {
        return navigateTo('/entrar')
    }
})