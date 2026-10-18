<template>
    <div class="marco">
        <header class="cabecera">
            <NuxtLink to="/" class="marca">
                {{ nombreAplicacion }}
            </NuxtLink>

            <nav v-if="haySesion" class="menu">
                <!--
                El menú se arma con las capacidades que manda la API, NO con una
                lista de roles escrita en TypeScript.

                La razón: duplicar la lista de roles en el navegador es la forma
                más común de que aparezca un botón que la API va a rechazar, y
                eso se ve como "el sistema a veces deja ver cosas".
                -->
                <NuxtLink to="/">Panel</NuxtLink>
                <NuxtLink to="/asistencia">Asistencia</NuxtLink>
                <NuxtLink to="/retiros">Retiros</NuxtLink>
                <NuxtLink to="/ninos">Niños</NuxtLink>
                <NuxtLink to="/responsables">Responsables</NuxtLink>
                <NuxtLink to="/autorizaciones">Autorizaciones</NuxtLink>

                <!--
                La ficha médica NO aparece en el menú para el administrativo.
                No porque esté oculta: porque no entra. Un enlace a una pantalla
                que va a dar 404 es una pantalla que alguien va a abrir
                esperando ver algo.
                -->
                <NuxtLink v-if="puede('abre_fichas_medicas')" to="/fichas-medicas">
                    Fichas médicas
                </NuxtLink>

                <NuxtLink v-if="puede('ve_pagos')" to="/pagos">Pagos</NuxtLink>
                <NuxtLink to="/incidencias">Incidencias</NuxtLink>
            </nav>

            <div v-if="haySesion" class="sesion">
                <span class="persona">
                    {{ nombreCompleto() }}
                    <small>{{ capacidades?.etiqueta_rol }}</small>
                </span>
                <button type="button" class="boton-plano" @click="salir()">
                    Salir
                </button>
            </div>
        </header>

        <main class="contenido">
            <slot />
        </main>

        <footer class="pie">
            <!--
            El aviso está siempre visible, en todas las pantallas.

            En una guardería, con menores y datos de salud, es más honesto
            recordarle a quien está operando que el sistema registra sus
            accesos, que confiar en que lo lean en un manual.
            -->
            <p>
                Sistema en desarrollo de Hefesto Studio. Los accesos a fichas médicas
                quedan registrados.
            </p>
        </footer>
    </div>
</template>

<script setup lang="ts">
/**
 * La forma de la aplicación.
 *
 * QUÉ HACE ESTE ARCHIVO Y QUÉ NO
 * ------------------------------
 * Pinta el menú y el pie. NO decide quién ve qué.
 *
 * La lista de enlaces se arma con `puede()`, que lee las capacidades que envía
 * la API. Si el rol de alguien cambia, la respuesta de `auth/yo` cambia, y el
 * menú cambia con ella.
 *
 * Ver docs/03.
 */
const config = useRuntimeConfig()
const nombreAplicacion = config.public.nombreAplicacion

const { usuario, capacidades, puede, nombreCompleto, salir, cargar } = useSesion()

// En el servidor el estado ya viene resuelto desde la carga inicial; en el
// navegador hay que traerlo. `callOnce` lo corre una sola vez por aplicación,
// en el servidor durante el primer render y en el cliente al hidratar.
await callOnce('sesion-inicial', async () => {
    await cargar(true)

    return true
})

const haySesion = computed((): boolean => usuario.value !== null)
</script>