<template>
    <div>
        <h1>Autorizaciones de retiro</h1>

        <div v-if="mensajeError !== null" class="aviso aviso-error">
            {{ mensajeError }}
        </div>

        <!--
        LO QUE HAY QUE MIRAR PRIMERO.

        Las autorizaciones que vencen pronto. El sistema avisa ANTES de que
        expiren: un sistema que avisa cuando ya venció no está avisando, está
        dejando constancia.

        El botón dice el número de días que el sistema está mirando. Eso no es un
        plazo legal: es una ayuda de operación, y sale de configuración. Ver
        docs/05.
        -->
        <section class="tarjeta">
            <h2>Por vencer</h2>

            <p class="ayuda" style="margin-top: 0;">
                El sistema avisa {{ dias }} días antes de que una autorización
                venza. Ese plazo es una ayuda de operación del centro, no una
                obligación legal.
            </p>

            <table v-if="porVencer.length > 0" class="tabla">
                <thead>
                    <tr>
                        <th scope="col">Niño</th>
                        <th scope="col">Responsable</th>
                        <th scope="col">Vence</th>
                        <th scope="col">Días</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="autorizacion in porVencer" :key="autorizacion.id">
                        <td>{{ autorizacion.nino?.nombre ?? '—' }}</td>
                        <td>{{ autorizacion.responsable?.nombre ?? '—' }}</td>
                        <td>{{ formatearFecha(autorizacion.vigente_hasta) }}</td>
                        <td>
                            <span
                                class="pildora"
                                :class="autorizacion.dias_para_vencer !== null && autorizacion.dias_para_vencer <= 5
                                    ? 'pildora-vencida'
                                    : 'pildora-aviso'"
                            >
                                {{ autorizacion.dias_para_vencer }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="ayuda">
                No hay autorizaciones por vencer en esta ventana.
            </p>
        </section>

        <!--
        EL HISTORIAL DE UN NIÑO.

        Se entra por niño, y con `?nino=`. Así es como se usa: uno llega por un
        niño concreto, no por un listado de toda la guardería.
        -->
        <section class="tarjeta">
            <h2>Autorizaciones de un niño</h2>

            <label class="etiqueta" for="nino">Niño</label>

            <select id="nino" v-model="ninoSeleccionado" class="campo" style="max-width: 26rem;">
                <option value="">Elija un niño</option>
                <option
                    v-for="nino in ninos"
                    :key="nino.id"
                    :value="nino.id"
                >
                    {{ nino.nombre }}
                </option>
            </select>

            <table v-if="autorizaciones.length > 0" class="tabla" style="margin-top: 1rem;">
                <thead>
                    <tr>
                        <th scope="col">Responsable</th>
                        <th scope="col">Estado</th>
                        <th scope="col">Desde</th>
                        <th scope="col">Hasta</th>
                        <th scope="col">Documento</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="autorizacion in autorizaciones" :key="autorizacion.id">
                        <td>{{ autorizacion.responsable?.nombre ?? '—' }}</td>

                        <td>
                            <span
                                class="pildora"
                                :class="pildoraDe(autorizacion)"
                            >
                                {{ etiquetaDe(autorizacion) }}
                            </span>

                            <!--
                            UNA AUTORIZACIÓN ANULADA MUESTRA SU MOTIVO.

                            Anular no es borrar. La autorización conserva su fila
                            y el motivo queda a la vista de quien intente retirar
                            al niño, para que pueda explicarle qué pasó.
                            -->
                            <p
                                v-if="autorizacion.motivo_anulacion !== null"
                                class="ayuda"
                                style="margin: 0.35rem 0 0;"
                            >
                                {{ autorizacion.motivo_anulacion }}
                            </p>
                        </td>

                        <td>{{ formatearFecha(autorizacion.vigente_desde) || 'Sin fecha' }}</td>
                        <td>{{ formatearFecha(autorizacion.vigente_hasta) || 'Sin vencimiento' }}</td>
                        <td>{{ autorizacion.documento_numero ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>

            <p v-else-if="ninoSeleccionado !== ''" class="ayuda">
                Este niño no tiene autorizaciones registradas.
            </p>
        </section>
    </div>
</template>

<script setup lang="ts">
/**
 * Las autorizaciones de retiro.
 *
 * EL AVISO ANTES DE VENCER
 * -----------------------
 * La pantalla separa lo que hay que mirar pronto de lo que ya pasó. Eso es a
 * propósito: "avise que ya venció" y "avise que va a vencer" son cosas
 * distintas, y sólo la segunda sirve para renewar a tiempo.
 *
 * "Sin vencimiento" es un caso real y no un dato faltante: hay centros donde la
 * autorización de un progenitor legal no vence, y es una decisión del centro.
 *
 * LO QUE NO HAY
 * ------------
 * No hay un formulario de alta en esta pantalla. Darla de alta es de dirección,
 * y esa pantalla es del centro de la dirección. Acá se consulta y se explica.
 *
 * Ver docs/03 y docs/07.
 */
import type { Autorizacion, Nino } from '~/tipos/api'

definePageMeta({
    middleware: 'auth'
})

useHead({ title: 'Autorizaciones' })

const ninos = ref<Nino[]>([])
const porVencer = ref<Autorizacion[]>([])
const autorizaciones = ref<Autorizacion[]>([])

const dias = ref<number>(15)
const ninoSeleccionado = ref<string>('')

const mensajeError = ref<string | null>(null)

onMounted(async () => {
    try {
        const respuesta = await llamarApi<{
            dias_aviso: number
            autorizaciones: Autorizacion[]
        }>('GET', 'autorizaciones/por-vencer')

        dias.value = respuesta.dias_aviso
        porVencer.value = respuesta.autorizaciones
    } catch (error) {
        mensajeError.value = mensajeLegible(error)
    }

    try {
        const respuesta = await llamarApi<{ ninos: Nino[] }>('GET', 'ninos?solo_activos=1')

        ninos.value = respuesta.ninos
    } catch (error) {
        mensajeError.value = mensajeLegible(error)
    }
})

watch(ninoSeleccionado, async (nuevo) => {
    autorizaciones.value = []

    if (nuevo === '') {
        return
    }

    try {
        const respuesta = await llamarApi<{ autorizaciones: Autorizacion[] }>(
            'GET',
            `ninos/${nuevo}/autorizaciones`
        )

        autorizaciones.value = respuesta.autorizaciones
    } catch (error) {
        mensajeError.value = mensajeLegible(error)
    }
})

function pildoraDe(autorizacion: Autorizacion): string {
    if (autorizacion.permite_retiro) {
        return autorizacion.por_vencer ? 'pildora-aviso' : 'pildora-ok'
    }

    return 'pildora-vencida'
}

function etiquetaDe(autorizacion: Autorizacion): string {
    if (!autorizacion.permite_retiro) {
        return autorizacion.estado_etiqueta
    }

    if (autorizacion.vigente_hasta === null) {
        return 'Vigente, sin vencimiento'
    }

    if (autorizacion.por_vencer) {
        return `Vence en ${autorizacion.dias_para_vencer} días`
    }

    return 'Vigente'
}
</script>