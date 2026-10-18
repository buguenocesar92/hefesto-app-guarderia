/**
 * El proxy hacia la API.
 *
 * POR QUÉ EL NAVEGADOR NO HABLA CON LA API
 * -----------------------------------------
 * La autenticación de esta aplicación es una cookie de sesión, y la cookie
 * viaja por HTTP normal. Si el navegador llamara a la API desde otro origen
 * tendría que exponer la cookie con credenciales a mano, y el token CSRF
 * quedaría a medias.
 *
 * Con el proxy, la cookie es de mismo origen y no hay nada que exponer. El
 * navegador llama a `/api/...` en el mismo servidor de Nuxt, y el servidor
 * reenvía la petición a la API con las cabeceras y el cuerpo.
 *
 * LO QUE ESTE ARCHIVO NO HACE
 * ---------------------------
 * No decide permisos. No filtra respuestas. No guarda nada en memoria entre
 * peticiones.
 *
 * Cada petición reenvía las cabeceras de sesión, y nada más. La decisión de qué
 * puede ver quién está en la API, que es el único lugar donde puede estar.
 */
export default defineEventHandler(async (event) => {
    const config = useRuntimeConfig(event)
    const ruta = getRouterParam(event, 'ruta') ?? ''
    const metodo = getMethod(event)

    // La URL se compone acá y no viene del cliente: si el cliente pudiera
    // elegir el destino, un valor mal puesto mandaría las cookies de sesión a
    // otro servidor.
    const url = `${config.apiInterna}/api/v1/${ruta}`

    const cabeceras: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
    }

    // Las cookies de sesión y el token CSRF se reenvían tal cual.
    const cookie = getRequestHeader(event, 'cookie')

    if (cookie !== undefined) {
        cabeceras.Cookie = cookie
    }

    const csrf = getRequestHeader(event, 'x-xsrf-token') ?? getRequestHeader(event, 'x-csrf-token')

    if (csrf !== undefined) {
        cabeceras['X-XSRF-TOKEN'] = csrf
    }

    const origen = getRequestHeader(event, 'origin')

    if (origen !== undefined) {
        cabeceras.Origin = origen
        cabeceras.Referer = getRequestHeader(event, 'referer') ?? origen
    }

    const cuerpo = ['GET', 'HEAD'].includes(metodo)
        ? undefined
        : await readBody(event).catch(() => undefined)

    let respuesta: globalThis.Response

    try {
        respuesta = await fetch(url, {
            method: metodo,
            headers: cabeceras,
            body: cuerpo === undefined ? undefined : JSON.stringify(cuerpo),
            redirect: 'manual'
        })
    } catch (error) {
        // La API no respondió. Esto SÍ es un error del sistema, y se dice como
        // tal: un 502 con un mensaje que la persona puede leer.
        throw createError({
            statusCode: 502,
            statusMessage: 'No se pudo contactar al servidor de Guardería. Intente de nuevo.'
        })
    }

    // Las cabeceras `Set-Cookie` de la respuesta se reenvían: es como la sesión
    // del servidor de la API llega al navegador a través del proxy.
    const cookies = respuesta.headers.getSetCookie()

    for (const valor of cookies) {
        appendResponseHeader(event, 'set-cookie', valor)
    }

    const texto = await respuesta.text()

    setResponseStatus(event, respuesta.status)
    setResponseHeader(event, 'content-type', 'application/json; charset=utf-8')

    if (texto === '') {
        return { mensaje: 'El servidor no devolvió contenido.' }
    }

    try {
        return JSON.parse(texto)
    } catch {
        // La API devolvió algo que no es JSON. Se devuelve tal cual, para que el
        // error se pueda leer en vez de tragárselo.
        return { mensaje: texto }
    }
})