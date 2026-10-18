<template>
    <div>
        <h1>Asistencia</h1>

        <section class="tarjeta">
            <label class="etiqueta" for="fecha">Día</label>

            <input
                id="fecha"
                v-model="fecha"
                type="date"
                class="campo"
                style="max-width: 16rem;"
            >

            <p class="ayuda">Cambiar el día y volver a cargar la lista.</p>
        </section>

        <div v-if="mensajeError !== null" class="aviso aviso-error">
            {{ mensajeError }}
        </div>

        <template v-else-if="resumen !== null">
            <div class="rejilla">
                <div class="numero">
                    <strong>{{ resumen.presentes }}</strong>
                    <span>En el centro</span>
                </div>

                <div class="numero">
                    <strong>{{ resumen.salidas_temporales }}</strong>
                    <span>Salidas temporales</span>
                </div>

                <div class="numero">
                    <strong>{{ resumen.retirados }}</strong>
                    <span>Retirados</span>
                </div>

                <div class="numero">
                    <strong>{{ resumen.ausentes }}</strong>
                    <span>Ausentes</span>
                </div>

                <!--
                "SIN AVISO" EN ROJO.

                La diferencia entre "no vino y avisaron" y "no vino y nadie
                avisó" es la diferencia entre una ausencia y una situación que
                hay que mirar hoy. Por eso va en rojo y con su propio número.
                -->
                <div
                    class="numero"
                    :class="{ 'numero-alerta': resumen.sin_aviso > 0 }"
                >
                    <strong>{{ resumen.sin_aviso }}</strong>
                    <span>Ausentes sin aviso</span>
                </div>
            </div>

            <section class="tarjeta">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th scope="col">Niño</th>
                            <th scope="col">Estado</th>
                            <th scope="col">Ingreso</th>
                            <th scope="col">Salida</th>
                            <th scope="col">Aviso</th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="asistencia in asistencias"
                            :key="asistencia.id"
                        >
                            <td>{{ nombreDelNino(asistencia.nino_id) }}</td>

                            <td>
                                <span
                                    class="pildora"
                                    :class="pildoraDeEstado(asistencia)"
                                >
                                    {{ asistencia.estado_etiqueta }}
                                </span>

                                <!--
                                UNA AUSENCIA SIN MOTIVO ES UNA CASILLA, NO UN DATO.

                                Y una asistencia cerrada sin registro de retiro es
                                un hueco que alguien tendría que reconstruir a
                                mano. Las dos cosas se muestran.
                                -->
                                <span
                                    v-if="asistencia.sin_aviso"
                                    class="pildora pildora-vencida"
                                    style="margin-left: 0.35rem;"
                                >
                                    Sin aviso
                                </span>

                                <span
                                    v-if="asistencia.sin_registro_de_retiro"
                                    class="pildora pildora-aviso"
                                    style="margin-left: 0.35rem;"
                                >
                                    Sin registro de retiro
                                </span>
                            </td>

                            <td>{{ horaDe(asistencia.ingreso_en) ?? '—' }}</td>

                            <td>{{ horaDe(asistencia.egreso_en) ?? '—' }}</td>

                            <td>
                                <span v-if="asistencia.aviso !== null && asistencia.aviso !== undefined">
                                    {{ asistencia.aviso.nombre }}
                                </span>

                                <span v-else-if="asistencia.motivo_ausencia !== null">
                                    {{ asistencia.motivo_ausencia }}
                                </span>

                                <span v-else>—</span>
                            </td>

                            <td>
                                <NuxtLink
                                    class="boton"
                                    :to="`/retiros?nino=${asistencia.nino_id}`"
                                >
                                    Retirar
                                </NuxtLink>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-if="asistencias.length === 0" class="ayuda">
                    No hay asistencias registradas para este día.
                </p>
            </section>
        </template>

        <div v-else-if="cargando" class="vacio">Cargando la asistencia...</div>
    </div>
</template>

<script setup lang="ts">
/**
 * La asistencia del día.
 *
 * LO QUE PONE ADELANTE
 * --------------------
 * La ausencia sin aviso. No la esconde, no la manda al final: la trae adelante,
 * con su propio número en rojo y con los nombres de los niños.
 *
 * Porque un niño que no llega y nadie avisa es una situación que hay que mirar a
 * las nueve de la mañana, no un dato que se descubre en el mes.
 *
 * Y una ausencia exige un motivo escrito: en el formulario y en una restricción
 * de la base. "No vino" sin motivo no es un dato.
 *
 * Ver docs/02.
 */
import type { Asistencia, Nino, ResumenAsistencia } from '~/tipos/api'

definePageMeta({
    middleware: 'auth'
})

useHead({ title: 'Asistencia' })

const ruta = useRoute()

const fecha = ref<string>(typeof ruta.query.fecha === 'string' ? ruta.query.fecha : hoyEnIso())
const asistencias = ref<Asistencia[]>([])
const nombres = ref<Record<string, string>>({})
const resumen = ref<ResumenAsistencia | null>(null)
const cargando = ref<boolean>(true)
const mensajeError = ref<string | null>(null)

onMounted(async () => {
    await cargar()

    // Los nombres de los niños se traen aparte: la respuesta de asistencia es
    // deliberadamente delgada, y unirla acá evita traer de más.
    try {
        const respuesta = await llamarApi<{ ninos: Nino[] }>('GET', 'ninos?solo_activos=1')

        for (const nino of respuesta.ninos) {
            nombres.value[nino.id] = nino.nombre
        }
    } catch {
        // Si no se pueden traer los nombres, la pantalla sigue siendo útil: se
        // muestra el identificador del niño, no un error que tape todo.
    }
})

async function cargar(): Promise<void> {
    cargando.value = true
    mensajeError.value = null

    try {
        const respuesta = await llamarApi<{
            asistencias: Asistencia[]
            resumen: ResumenAsistencia
        }>('GET', `asistencias?fecha=${encodeURIComponent(fecha.value)}`)

        asistencias.value = respuesta.asistencias
        resumen.value = respuesta.resumen
    } catch (error) {
        mensajeError.value = mensajeLegible(error)
    } finally {
        cargando.value = false
    }
}

watch(fecha, () => {
    void cargar()
})

function nombreDelNino(ninoId: string): string {
    return nombres.value[ninoId] ?? `Niño ${ninoId.slice(0, 8)}`
}

function pildoraDeEstado(asistencia: Asistencia): string {
    if (asistencia.sin_aviso) {
        return 'pildora-vencida'
    }

    if (asistencia.estado === 'ausente') {
        return 'pildora-aviso'
    }

    return 'pildora-ok'
}

/**
 * La hora de un dato ISO, sin pasar por `Date`.
 *
 * Se parte la cadena: `new Date()` interpreta una cadena sin zona horaria en
 * hora local y puede mover la hora en un día. Ver `app/utils/api.ts`.
 */
function horaDe(momento: string | null): string | null {
    if (momento === null || momento === '') {
        return null
    }

    const parte = momento.split('T')[1] ?? ''

    return parte.slice(0, 5)
}

/**
 * El día de hoy en ISO, a partir de la hora LOCAL.
 *
 * Se arma con los números de la fecha local y no con `toISOString()`, que
 * devuelve en UTC: en Chile, después de las ocho de la tarde, `toISOString()`
 * ya devuelve el día siguiente.
 */
function hoyEnIso(): string {
    const ahora = new Date()
    const mes = String(ahora.getMonth() + 1).padStart(2, '0')
    const dia = String(ahora.getDate()).padStart(2, '0')

    return `${ahora.getFullYear()}-${mes}-${dia}`
}
</script>