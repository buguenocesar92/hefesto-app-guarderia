<template>
    <div class="aplicacion">
        <NuxtLayout>
            <NuxtPage />
        </NuxtLayout>
    </div>
</template>

<script setup lang="ts">
/**
 * La raíz de la interfaz.
 *
 * No hace nada por su cuenta: el trabajo está en `layouts/default.vue` y en las
 * páginas. Este archivo existe para que Nuxt tenga un punto de entrada, y para
 * que quede escrito que la aplicación no tiene plugins de cliente.
 *
 * LA INTERFAZ NO DECIDE PERMISOS
 * -----------------------------
 * Ninguna pantalla decide si alguien puede ver algo. Lo lee de las capacidades
 * que manda la API, y la API es la que manda. Ver `composables/useSesion.ts`.
 */
const config = useRuntimeConfig()

useHead({
    titleTemplate: (titulo?: string | null) => {
        const nombre = config.public.nombreAplicacion

        return titulo === null || titulo === undefined || titulo === ''
            ? nombre
            : `${titulo} · ${nombre}`
    }
})
</script>