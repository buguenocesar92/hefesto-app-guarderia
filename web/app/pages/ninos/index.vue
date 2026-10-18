<template>
    <div>
        <h1>Niños</h1>

        <div v-if="mensajeError !== null" class="aviso aviso-error">
            {{ mensajeError }}
        </div>

        <section class="tarjeta">
            <label class="etiqueta" for="buscar">Buscar</label>

            <input
                id="buscar"
                v-model="busqueda"
                type="search"
                class="campo"
                placeholder="Apellido o nombre"
                autocomplete="off"
                @keyup.enter="buscar"
            >

            <table v-if="ninos.length > 0" class="tabla" style="margin-top: 1rem;">
                <thead>
                    <tr>
                        <th scope="col">Niño</th>
                        <th scope="col">Edad</th>
                        <th scope="col">Responsables</th>
                        <th scope="col">Ficha médica</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="nino in ninos" :key="nino.id">
                        <td>
                            <strong>{{ nino.nombre }}</strong>
                            <br>
                            <small style="color: var(--tinta-suave);">
                                Nacido el {{ formatearFecha(nino.fecha_nacimiento) }}
                            </small>
                        </td>

                        <td>{{ nino.edad }}</td>

                        <td>
                            <span
                                v-for="responsable in nino.responsables ?? []"
                                :key="responsable.id"
                                style="display: block;"
                            >
                                {{ responsable.nombre }}
                                <small style="color: var(--tinta-suave);">
                                    ({{ responsable.parentesco ?? 'responsable' }})
                                </small>
                            </span>

                            <span
                                v-if="(nino.responsables ?? []).length === 0"
                                class="pildora pildora-vencida"
                            >
                                Sin responsables
                            </span>
                        </td>

                        <!--
                        SÍ O NO. NUNCA QUÉ DICE.

                        Saber que existe una ficha y poder abrirla son dos cosas
                        distintas, y sólo la segunda requiere permiso. Esta
                        columna no trae ni una palabra de la ficha.

                        Ver `App\Http\Resources\NinoResource` y docs/03.
                        -->
                        <td>
                            <NuxtLink
                                v-if="nino.tiene_ficha_medica && puede('abre_fichas_medicas')"
                                class="pildora pildora-ok"
                                :to="`/fichas-medicas?nino=${nino.id}`"
                            >
                                Ver
                            </NuxtLink>

                            <span v-else-if="!nino.tiene_ficha_medica" class="pildora pildora-aviso">
                                Sin ficha
                            </span>

                            <span v-else class="pildora pildora-neutra">
                                No disponible
                            </span>
                        </td>

                        <td>
                            <NuxtLink class="boton" :to="`/ninos/${nino.id}`">
                                Abrir
                            </NuxtLink>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else-if="!cargando" class="ayuda">
                No hay niños que coincidan con la búsqueda.
            </p>
        </section>
    </div>
</template>

<script setup lang="ts">
/**
 * La lista de niños.
 *
 * LO QUE ESTA LISTA NO TRAE
 * -------------------------
 * Ninguna palabra de la ficha médica. La columna "Ficha médica" dice si existe,
 * nunca qué dice.
 *
 * Y un niño sin ficha sale con una pildora ámbar, porque un niño sin ficha es
 * un niño al que no se le puede servir el almuerzo con seguridad. Eso tiene que
 * verse antes de las once de la mañana, no en el momento de servirlo.
 *
 * Ver docs/02.
 */
import type { Nino } from '~/tipos/api'

definePageMeta({
    middleware: 'auth'
})

useHead({ title: 'Niños' })

const { puede } = useSesion()

const ninos = ref<Nino[]>([])
const busqueda = ref<string>('')
const cargando = ref<boolean>(false)
const mensajeError = ref<string | null>(null)

onMounted(async () => {
    await cargar()
})

async function cargar(): Promise<void> {
    cargando.value = true
    mensajeError.value = null

    try {
        const consulta = busqueda.value.trim().length >= 2
            ? `?buscar=${encodeURIComponent(busqueda.value.trim())}&solo_activos=1`
            : '?solo_activos=1'

        const respuesta = await llamarApi<{ ninos: Nino[] }>('GET', `ninos${consulta}`)

        ninos.value = respuesta.ninos
    } catch (error) {
        mensajeError.value = mensajeLegible(error)
    } finally {
        cargando.value = false
    }
}

async function buscar(): Promise<void> {
    await cargar()
}
</script>