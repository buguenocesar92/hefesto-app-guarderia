<template>
    <div class="ingreso">
        <div class="tarjeta">
            <h1>{{ nombreAplicacion }}</h1>

            <p class="ayuda" style="margin-bottom: 1.5rem;">
                Elija su guardería para ingresar.
            </p>

            <!--
            ESTA LISTA VIENE DE `vw_guarderias_publicas`.

            La vista expone TRES columnas: identificador, nombre y el nombre
            corto. No expone razón social, dirección, teléfono ni correo, porque
            antes del ingreso no hay razón para saber nada de un centro con el
            que la persona todavía no se ha identificado.

            Ver `Publico\GuarderiasPublicasController` y docs/03.
            -->
            <label class="etiqueta" for="guarderia">Guardería</label>

            <select
                id="guarderia"
                v-model="slug"
                class="campo"
                :disabled="cargando"
            >
                <option value="" disabled>
                    {{ cargando ? 'Cargando...' : 'Elija una guardería' }}
                </option>
                <option
                    v-for="centro in guarderias"
                    :key="centro.id"
                    :value="centro.slug"
                >
                    {{ centro.nombre }}
                </option>
            </select>

            <button
                type="button"
                class="boton boton-principal"
                style="width: 100%; margin-top: 1.25rem;"
                :disabled="cargando || slug === ''"
                @click="ingresar"
            >
                Ingresar
            </button>

            <p v-if="mensajeError !== null" class="aviso aviso-error" style="margin-top: 1.25rem;">
                {{ mensajeError }}
            </p>

            <p v-if="mensajeAviso !== null" class="aviso aviso-info" style="margin-top: 1.25rem;">
                {{ mensajeAviso }}
            </p>
        </div>
    </div>
</template>

<script setup lang="ts">
/**
 * La pantalla de ingreso.
 *
 * QUÉ HACE Y QUÉ NO
 * -----------------
 * Manda a la persona al proveedor de identidad y vuelve cuando vuelve.
 *
 * NO pide usuario ni contraseña. La contraseña está en Authentik y esta
 * aplicación nunca la ve: recibe un token de identificación, verifica su firma
 * y abre sesión.
 *
 * POR QUÉ HAY QUE ELEGIR GUARDERÍA
 * -------------------------------
 * Porque una instalación puede atender a varias guarderías, y el equipo de
 * Authentik dice el ROL pero no cuál centro.
 *
 * Y la lista sale de la vista pública, que expone tres columnas. Antes del
 * ingreso, lo único que se puede saber de un centro es su nombre.
 *
 * Ver docs/03 y docs/04.
 */
import type { GuarderiaPublica } from '~/tipos/api'

definePageMeta({
    layout: 'default'
})

useHead({ title: 'Ingresar' })

const config = useRuntimeConfig()
const nombreAplicacion = config.public.nombreAplicacion

const { cargar } = useSesion()

const guarderias = ref<GuarderiaPublica[]>([])
const slug = ref<string>('')
const cargando = ref<boolean>(true)
const mensajeError = ref<string | null>(null)
const mensajeAviso = ref<string | null>(null)

onMounted(async () => {
    try {
        const respuesta = await llamarApi<{ guarderias: GuarderiaPublica[] }>('GET', 'guarderias')

        guarderias.value = respuesta.guarderias
    } catch (error) {
        mensajeError.value = mensajeLegible(error)
    } finally {
        cargando.value = false
    }
})

async function ingresar(): Promise<void> {
    if (slug.value === '') {
        return
    }

    cargando.value = true
    mensajeError.value = null
    mensajeAviso.value = null

    try {
        const respuesta = await llamarApi<{ url: string }>(
            'GET',
            `auth/ingreso?guarderia=${encodeURIComponent(slug.value)}`
        )

        // La API devuelve la dirección del proveedor de identidad. El navegador
        // va directo allá: el token nunca pasa por esta aplicación en el viaje
        // de ida, y vuelve por el retorno que el navegador consulta.
        window.location.href = respuesta.url
    } catch (error) {
        mensajeError.value = mensajeLegible(error)
        cargando.value = false
    }
}
</script>

<style scoped>
.ingreso {
    display: flex;
    justify-content: center;
    padding-top: 2rem;
}

.ingreso .tarjeta {
    width: 100%;
    max-width: 27rem;
}
</style>