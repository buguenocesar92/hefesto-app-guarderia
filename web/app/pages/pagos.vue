<template>
    <div>
        <h1>Pagos y matrículas</h1>

        <div v-if="mensajeError !== null" class="aviso aviso-error">
            {{ mensajeError }}
        </div>

        <div class="rejilla">
            <div class="numero">
                <strong>{{ matriculas.length }}</strong>
                <span>Matrículas</span>
            </div>

            <div class="numero">
                <strong>{{ formatearPesos(totalAdeudado) }}</strong>
                <span>Total adeudado</span>
            </div>
        </div>

        <section v-if="matriculas.length > 0" class="tarjeta">
            <table class="tabla">
                <thead>
                    <tr>
                        <th scope="col">Niño</th>
                        <th scope="col">Plan</th>
                        <th scope="col">Precio</th>
                        <th scope="col">Pagado</th>
                        <th scope="col">Saldo</th>
                        <th scope="col">Pagos</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="matricula in matriculas" :key="matricula.id">
                        <td>{{ matricula.nino_id.slice(0, 8) }}</td>
                        <td>{{ matricula.plan?.nombre ?? 'Sin plan' }}</td>
                        <td>{{ matricula.plan === null ? '—' : formatearPesos(matricula.plan.precio_clp) }}</td>
                        <td>{{ formatearPesos(matricula.total_pagado) }}</td>

                        <td>
                            <span
                                class="pildora"
                                :class="matricula.saldo_clp > 0 ? 'pildora-vencida' : 'pildora-ok'"
                            >
                                {{ matricula.saldo_legible }}
                            </span>
                        </td>

                        <td>{{ (pagosDe(matrricula.id) ?? []).length }}</td>
                    </tr>
                </tbody>
            </table>

            <p class="ayuda">
                El saldo se calcula restando los pagos registrados al precio del
                plan. No es un número guardado: un saldo guardado se queda viejo
                el día que alguien registra un pago y no lo actualiza.
            </p>
        </section>

        <section v-if="matriculaSeleccionada !== null" class="tarjeta">
            <h2>Pagos de esta matrícula</h2>

            <table class="tabla">
                <thead>
                    <tr>
                        <th scope="col">Fecha</th>
                        <th scope="col">Monto</th>
                        <th scope="col">Método</th>
                        <th scope="col">Estado</th>
                        <th scope="col">Motivo de anulación</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="pago in pagosDe(matriculaSeleccionada)" :key="pago.id">
                        <td>{{ formatearFecha(pago.pagado_en) }}</td>
                        <td>{{ pago.monto_legible }}</td>
                        <td>{{ pago.metodo ?? '—' }}</td>

                        <td>
                            <span
                                class="pildora"
                                :class="pago.suma_al_saldo ? 'pildora-ok' : 'pildora-vencida'"
                            >
                                {{ pago.estado_etiqueta }}
                            </span>
                        </td>

                        <!--
                        UN PAGO ANULADO SIGUE SIENDO UN PAGO.

                        Anular no es borrar. Si los pagos anulados no se vieran, el
                        saldo de una familia dejaría de poder reconstruirse.
                        -->
                        <td>{{ pago.motivo_anulacion ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>

            <p v-if="(pagosDe(matriculaSeleccionada) ?? []).length === 0" class="ayuda">
                No hay pagos registrados en esta matrícula.
            </p>
        </section>

        <p v-if="matriculas.length === 0 && !cargando" class="vacio">
            <strong>No hay matrículas registradas</strong>
            Los planes y sus precios los carga el centro. Esta aplicación no trae
            ninguna tarifa por omisión, porque no se conoce lo que cobra cada
            guardería.
        </p>
    </div>
</template>

<script setup lang="ts">
/**
 * Pagos y matrículas.
 *
 * LO QUE ESTA APLICACIÓN NO TIENE
 * --------------------------------
 * Una tarifa. Ni un plan básico de nada, ni una mensualidad de nada.
 *
 * No se conoce lo que cobra cada guardería, y escribir un precio sería
 * inventarlo. El centro carga sus planes con sus montos, y esta pantalla los
 * muestra.
 *
 * EL SALDO
 * --------
 * Sale calculado de la API: precio del plan menos los pagos NO anulados. No es
 * un número guardado, porque un saldo guardado se queda viejo el día que
 * alguien registra un pago y no lo actualiza.
 *
 * Ver docs/02.
 */
import type { Matricula, Pago } from '~/tipos/api'

definePageMeta({
    middleware: 'auth'
})

useHead({ title: 'Pagos' })

const { puede } = useSesion()

const matriculas = ref<Matricula[]>([])
const pagos = ref<Pago[]>([])
const matriculaSeleccionada = ref<string | null>(null)
const cargando = ref<boolean>(true)
const mensajeError = ref<string | null>(null)

const totalAdeudado = computed((): number =>
    matriculas.value.reduce((suma, matricula) => suma + Math.max(0, matricula.saldo_clp), 0)
)

onMounted(async () => {
    if (!puede('ve_pagos')) {
        cargando.value = false
        return
    }

    try {
        const respuesta = await llamarApi<{
            matriculas: Matricula[]
            total_adeudado_clp: number
        }>('GET', 'pagos?solo_activas=1')

        matriculas.value = respuesta.matriculas
    } catch (error) {
        mensajeError.value = mensajeLegible(error)
    } finally {
        cargando.value = false
    }
})

function pagosDe(matrriculaId: string | null): Pago[] {
    if (matriculaId === null) {
        return []
    }

    return pagos.value.filter((pago) => pago.matrricula_id === matriculaId)
}
</script>