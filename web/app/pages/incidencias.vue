<template>
    <div>
        <h1>Incidencias</h1>

        <!--
        POR QUÉ ESTA PANTALLA EXISTE
        ---------------------------
        Porque es lo que sostiene una conversación difícil con la familia tres
        meses después. Sin registro, la conversación es "me parece que en
        noviembre pasó algo". Con registro, es "el 14 de noviembre, a las once,
        una educadora registró esto y se hizo aquello".

        Y por eso las incidencias NO SE BORRAN. No es una omisión pendiente: la
        base tiene un disparador que rechaza el borrado, aunque alguien escriba
        con un cliente de SQL. Ver docs/07.
        -->
        <section class="tarjeta">
            <label class="etiqueta" for="nino">Niño</label>

            <select
                id="nino"
                v-model="ninoSeleccionado"
                class="campo"
                style="max-width: 26rem;"
            >
                <option value="">Elija un niño</option>
                <option
                    v-for="nino in ninos"
                    :key="nino.id"
                    :value="nino.id"
                >
                    {{ nino.nombre }}
                </option>
            </select>

            <p class="ayuda">
                Para registrar una incidencia nueva, abra el niño desde
                <NuxtLink to="/ninos">la lista de niños</NuxtLink>.
            </p>
        </section>

        <div v-if="mensajeError !== null" class="aviso aviso-error">
            {{ mensajeError }}
        </div>

        <section v-if="incidencias.length > 0" class="tarjeta">
            <table class="tabla">
                <thead>
                    <tr>
                        <th scope="col">Cuándo</th>
                        <th scope="col">Gravedad</th>
                        <th scope="col">Qué pasó</th>
                        <th scope="col">Qué se hizo</th>
                        <th scope="col">Registrada por</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="incidencia in incidencias" :key="incidencia.id">
                        <td>{{ formatearFecha(incidencia.ocurrido_en) }}</td>

                        <td>
                            <span
                                class="pildora"
                                :class="pildoraDe(incidencia)"
                            >
                                {{ incidencia.gravedad_etiqueta }}
                            </span>

                            <span
                                v-if="incidencia.requiere_seguimiento"
                                class="pildora pildora-aviso"
                                style="margin-left: 0.35rem;"
                            >
                                Seguimiento
                            </span>
                        </td>

                        <td>{{ incidencia.descripcion }}</td>

                        <!--
                        LA SEGUNDA COLUMNA NO ES OPCIONAL.

                        Una incidencia sin acción tomada deja registro del
                        problema y no de la respuesta, y con la respuesta no se
                        sostiene una conversación con la familia.
                        -->
                        <td>{{ incidencia.accion_tomada }}</td>

                        <td>{{ incidencia.autora ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <p v-else-if="ninoSeleccionado !== ''" class="vacio">
            <strong>Sin incidencias registradas</strong>
            Este niño no tiene incidencias en su historial. Eso también es un
            dato: es lo que se responde cuando alguien pregunta.
        </p>
    </div>
</template>

<script setup lang="ts">
/**
 * El registro de incidencias.
 *
 * LAS DOS COLUMNAS
 * ----------------
 * "Qué pasó" y "Qué se hizo". La segunda no es opcional, ni aquí ni en el
 * formulario ni en la base: los mínimos salen de configuración.
 *
 * Una incidencia sin acción deja registro del problema y no de la respuesta, y
 * con la respuesta no se sostiene una conversación con la familia.
 *
 * LA GRAVEDAD TIENE SIGNIFICADO
 * ----------------------------
 * Cuatro grados con descripciones explícitas, y no un número del 1 al 10.
 * "Leve" y "grave" no significan lo mismo en dos centros, y es la dirección del
 * centro la que decide qué significa acá. Un 1 al 10 haría que dos educadoras
 * llamaran 7 a cosas muy distintas, y la conversación con la familia empieza
 * por ahí.
 *
 * Ver docs/02 y docs/07.
 */
import type { GravedadIncidencia, Incidencia, Nino } from '~/tipos/api'

definePageMeta({
    middleware: 'auth'
})

useHead({ title: 'Incidencias' })

const ninos = ref<Nino[]>([])
const incidencias = ref<Incidencia[]>([])
const ninoSeleccionado = ref<string>('')
const mensajeError = ref<string | null>(null)

onMounted(async () => {
    try {
        const respuesta = await llamarApi<{ ninos: Nino[] }>('GET', 'ninos?solo_activos=1')

        ninos.value = respuesta.ninos
    } catch (error) {
        mensajeError.value = mensajeLegible(error)
    }
})

watch(ninoSeleccionado, async (nuevo) => {
    incidencias.value = []
    mensajeError.value = null

    if (nuevo === '') {
        return
    }

    try {
        const respuesta = await llamarApi<{ incidencias: Incidencia[] }>(
            'GET',
            `ninos/${nuevo}/incidencias`
        )

        incidencias.value = respuesta.incidencias
    } catch (error) {
        mensajeError.value = mensajeLegible(error)
    }
})

function pildoraDe(incidencia: Incidencia): string {
    const gravedad: GravedadIncidencia = incidencia.gravedad

    if (gravedad === 'critica' || gravedad === 'relevante') {
        return 'pildora-vencida'
    }

    if (gravedad === 'moderada') {
        return 'pildora-aviso'
    }

    return 'pildora-neutra'
}
</script>