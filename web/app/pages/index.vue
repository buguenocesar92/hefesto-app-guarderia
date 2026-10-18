<template>
    <div>
        <h1>Panel del día</h1>

        <p class="ayuda" style="margin-bottom: 1.5rem;">
            {{ formatearFecha(panel?.fecha) }}
        </p>

        <div v-if="mensajeError !== null" class="aviso aviso-error">
            {{ mensajeError }}
        </div>

        <template v-else-if="panel !== null">
            <!--
            LO QUE ESTÁ AHORA.

            Cuatro números, grandes, sin adornos. Es lo que se mira al abrir la
            pantalla, muchas veces al día.
            -->
            <div class="rejilla">
                <div class="numero">
                    <strong>{{ panel.hoy.presentes }}</strong>
                    <span>En el centro ahora</span>
                </div>

                <div class="numero">
                    <strong>{{ panel.hoy.retirados }}</strong>
                    <span>Ya retirados hoy</span>
                </div>

                <div class="numero">
                    <strong>{{ panel.hoy.ausentes }}</strong>
                    <span>Ausentes hoy</span>
                </div>

                <!--
                "NO LLEGÓ Y NADIE AVISÓ".

                Va en rojo y va con los NOMBRES, no sólo con el número. Un número
                no hace que nadie llame a una familia; un nombre sí.

                Es la situación que el sistema tiene que hacer visible.
                -->
                <div
                    class="numero"
                    :class="{ 'numero-alerta': panel.hoy.ausentes_sin_aviso > 0 }"
                >
                    <strong>{{ panel.hoy.ausentes_sin_aviso }}</strong>
                    <span>Sin aviso de que no venirían</span>
                </div>
            </div>

            <section v-if="panel.hoy.ausentes_sin_aviso_detalle.length > 0" class="tarjeta">
                <h2>Niños que no llegaron y nadie avisó</h2>

                <p class="ayuda" style="margin-top: 0;">
                    Avise a la dirección del centro para que registre la ausencia
                    con su motivo.
                </p>

                <table class="tabla">
                    <thead>
                        <tr>
                            <th scope="col">Niño</th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in panel.hoy.ausentes_sin_aviso_detalle"
                            :key="item.nino_id"
                        >
                            <td>{{ item.nombre }}</td>
                            <td>
                                <NuxtLink class="boton" :to="`/asistencia?fecha=${panel.fecha}`">
                                    Ver asistencia
                                </NuxtLink>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!--
            LO QUE QUEDA PENDIENTE.

            Las autorizaciones por vencer van PRIMERO entre los pendientes,
            porque "se avisó antes de que expirara" es una promesa que esta
            aplicación le hace a la familia. Ver docs/07.
            -->
            <h2>Pendientes</h2>

            <div class="rejilla">
                <div
                    class="numero"
                    :class="{ 'numero-alerta': panel.pendientes.autorizaciones_por_vencer > 0 }"
                >
                    <strong>{{ panel.pendientes.autorizaciones_por_vencer }}</strong>
                    <span>
                        Autorizaciones por vencer, en los próximos
                        {{ panel.pendientes.dias_anticipacion_del_aviso }} días
                    </span>
                </div>

                <div class="numero">
                    <strong>{{ panel.pendientes.incidencias_con_seguimiento }}</strong>
                    <span>Incidencias con seguimiento pendiente</span>
                </div>

                <div
                    class="numero"
                    :class="{ 'numero-alerta': panel.pendientes.retiros_rechazados_hoy > 0 }"
                >
                    <strong>{{ panel.pendientes.retiros_rechazados_hoy }}</strong>
                    <span>Intentos de retiro rechazados hoy</span>
                </div>
            </div>

            <section v-if="puede('abre_fichas_medicas')" class="tarjeta">
                <h2>Fichas médicas</h2>

                <p style="margin-bottom: 0;">
                    Antes de servir el almuerzo, conviene saber si hay niños con
                    restricciones. La ficha de cada uno se abre por separado, y
                    cada apertura queda registrada.
                </p>
            </section>
        </template>

        <div v-else-if="cargando" class="vacio">
            Cargando el panel...
        </div>
    </div>
</template>

<script setup lang="ts">
/**
 * El panel: lo que hay que mirar hoy.
 *
 * LO QUE NO ESTÁ AQUÍ
 * -------------------
 * Ninguna alerta de alergias ni de medicación.
 *
 * El panel se usa de pie, todas las mañanas, y no es el lugar donde vive el
 * dato sensible de un menor. Ponerlo ahí lo haría visible a quien pase por
 * detrás de la pantalla, y la ficha médica ya tiene su propio lugar: una ruta
 * con middleware, una bitácora y un acceso escalonado por rol.
 *
 * Ver docs/02 y docs/07.
 */
import type { Panel } from '~/tipos/api'

definePageMeta({
    middleware: 'auth'
})

useHead({ title: 'Panel' })

const { puede } = useSesion()

const panel = ref<Panel | null>(null)
const cargando = ref<boolean>(true)
const mensajeError = ref<string | null>(null)

onMounted(async () => {
    try {
        panel.value = await llamarApi<Panel>('GET', 'panel')
    } catch (error) {
        mensajeError.value = mensajeLegible(error)
    } finally {
        cargando.value = false
    }
})
</script>