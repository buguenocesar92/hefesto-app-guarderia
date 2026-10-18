import { defineNuxtConfig } from 'nuxt/config'

/**
 * Configuración de la interfaz de Guardería.
 *
 * EL CÓMO SE HABLA CON LA API
 * ----------------------------
 * El navegador NUNCA habla directamente con la API. Todo pasa por el servidor de
 * Nuxt, en `server/api/[...ruta].ts`.
 *
 * La razón es la sesión: la cookie de sesión viaja por HTTP normal, y el token
 * CSRF viaja como cabecera `X-XSRF-TOKEN`. Si el navegador llamara a la API
 * desde otro origen, tendría que exponer la cookie con credenciales y a mano,
 * y el CSRF quedaría a medias.
 *
 * Con el proxy de Nuxt, la cookie es de mismo origen y no hay nada que exponer.
 * Ver docs/01.
 *
 * EL POR DEFIAL ES PÚBLICO
 * -----------------------
 * Esta aplicación tiene datos de menores y de salud. Que el código de las
 * pantallas quede disponible en el navegador del usuario no agrega ningún
 * riesgo: el código de una pantalla no es un dato de un niño, y el permiso se
 * comprueba en la API, no en el navegador.
 *
 * `runtimeConfig.public` es lo único que llega al navegador. La dirección de la
 * API no está ahí a propósito: la resuelve el servidor. Ver docs/05.
 */
export default defineNuxtConfig({
    compatibilityDate: '2026-10-18',

    devtools: {
        enabled: false
    },

    // Sin datos de menores ni de salud: `ssr` evita que el contenido llegue en
    // el HTML inicial cuando no hace falta, y `noScripts` impide que se inyecte
    // código de terceros.
    ssr: true,

    css: ['~/assets/css/main.css'],

    runtimeConfig: {
        // La dirección de la API la usa SÓLO el servidor. No va en `public`.
        apiInterna: process.env.NUXT_API_INTERNA ?? 'http://api:8000',

        public: {
            // El nombre del producto, para el título y la pantalla de ingreso.
            nombreAplicacion: process.env.NUXT_PUBLIC_NOMBRE ?? 'Guardería'
        }
    },

    app: {
        head: {
            htmlAttrs: {
                lang: 'es'
            },
            title: process.env.NUXT_PUBLIC_NOMBRE ?? 'Guardería',
            meta: [
                { charset: 'utf-8' },
                { name: 'viewport', content: 'width=device-width, initial-scale=1' },
                {
                    name: 'robots',
                    content: 'noindex, nofollow'
                }
            ]
        }
    },

    nitro: {
        // El servidor de Nuxt es el único que habla con la API.
        routeRules: {
            '/api/**': {
                cors: false
            }
        }
    }
})