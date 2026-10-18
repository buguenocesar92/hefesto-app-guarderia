<template>
    <div>
        <h1>Fichas médicas</h1>

        <!--
        EL AVISO DE LA PANTALLA.

        Esta pantalla muestra dato de salud de un menor. Se abre una por una, con
        el niño elegido, y CADA apertura queda registrada con el rol y la hora.

        Y el rol administrativo no entra: la API le responde 404. Esta pantalla
        no es la que decide eso —es la que lo muestra—, pero el texto de abajo
        está para que quien llegue sepa POR QUÉ.
        -->
        <div class="aviso aviso-info">
            <strong>Estas fichas son dato de salud de un menor.</strong>
            <p style="margin: 0.35rem 0 0;">
                Cada apertura y cada guardado queda registrado con su rol y su
                hora. El sistema no guarda una copia de lo que se abrió y no
                envía estos datos a ningún servicio externo.
            </p>
        </div>

        <div v-if="!puedeVer" class="vacio">
            <strong>Su rol no abre fichas médicas</strong>
            {{ capacidades?.acceso_ficha_medica }}
        </div>

        <template v-else>
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

                <p v-if="ninosSinFicha.length > 0" class="ayuda">
                    Sin ficha registrada: {{ ninosSinFicha.length }} de los niños
                    activos no tienen una. Sin ficha no se puede saber qué
                    servirle en el almuerzo.
                </p>
            </section>

            <div v-if="mensajeError !== null" class="aviso aviso-error">
                {{ mensajeError }}
            </div>

            <section v-if="ficha !== null" class="tarjeta">
                <h2>{{ ficha.nino?.nombre ?? 'Ficha médica' }}</h2>

                <!--
                LAS RESTRICCIONES, PRIMERO.

                Lo primero que se necesita al abrir esta pantalla es lo que hay
                que saber antes de servir el almuerzo. El resto va después.
                -->
                <div
                    v-if="ficha.tiene_restricciones"
                    class="estado estado-vencido"
                    style="justify-content: flex-start; text-transform: none; font-weight: 600;"
                >
                    <span>Este niño tiene restricciones alimentarias registradas.</span>
                </div>

                <h3>Alergias</h3>
                <p>{{ ficha.alergias ?? 'Sin alergias registradas.' }}</p>

                <h3>Alimentos prohibidos</h3>
                <p>{{ ficha.alimentos_prohibidos ?? 'Sin alimentos prohibidos registrados.' }}</p>

                <h3>Indicación de alimentación</h3>
                <p>{{ ficha.alimentacion ?? 'Sin indicación registrada.' }}</p>

                <h3>Medicación</h3>
                <p>{{ ficha.medicacion ?? 'Sin medicación registrada.' }}</p>

                <!--
                ESTA CAJA ES DEL ADULTO RESPONSABLE, NO DEL SISTEMA.

                Que esté marcada no le da permiso a nadie para leer la ficha. Lo
                dice en el texto porque confundirlos sería un error grave.
                -->
                <div
                    class="aviso"
                    :class="ficha.autoriza_medicamento ? 'aviso-info' : 'aviso-error'"
                >
                    <strong>
                        {{ ficha.autoriza_medicamento
                            ? 'La familia autoriza administrar medicación en el centro.'
                            : 'La familia NO autoriza administrar medicación en el centro.' }}
                    </strong>
                    <p style="margin: 0.35rem 0 0;">
                        Lo decidió el adulto responsable del niño, no el sistema.
                        Marcar esta casilla no habilita a nadie a ver esta ficha.
                    </p>
                </div>

                <h3>Plan de emergencia</h3>
                <p>{{ ficha.plan_emergencia ?? 'Sin plan registrado.' }}</p>

                <h3>Contacto de emergencia</h3>
                <p>
                    {{ ficha.contacto_emergencia_nombre ?? 'Sin nombre registrado' }}
                    <template v-if="ficha.contacto_emergencia_telefono !== null">
                        · {{ ficha.contacto_emergencia_telefono }}
                    </template>
                </p>

                <p class="ayuda">
                    Última actualización:
                    {{ formatearFecha(ficha.actualizado_en) }}
                </p>
            </section>
        </template>
    </div>
</template>

<script setup lang="ts">
/**
 * La ficha médica de un niño. La pantalla más restringida de la aplicación.
 *
 * LO QUE ESTA PANTALLA HACE Y LO QUE HACE LA API
 * -----------------------------------------------
 * Esta pantalla MUESTRA. La API DECIDE.
 *
 * El rol administrativo recibe 404 al pedir una ficha. Si el botón de esta
 * pantalla se queda deshabilitado para él, es por cortesía; la protección real
 * no está acá, está en la Policy y en la Row Level Security.
 *
 * Y si alguien falsea la vista desde el navegador, la API igual devuelve 404 y
 * el intento queda anotado en la bitácora.
 *
 * POR QUÉ EL AVISO DE ARRIBA
 * -------------------------
 * Porque esta pantalla se abre con alguien parado al lado, y una persona tiene
 * que saber de antemano que lo que está viendo queda registrado. No descubrirlo
 * después.
 *
 * Ver docs/03 y docs/07.
 */
import type { FichaMedica, Nino } from '~/tipos/api'

definePageMeta({
    middleware: 'auth'
})

useHead({ title: 'Fichas médicas' })

const { capacidades, puede } = useSesion()

const ninos = ref<Nino[]>([])
const ficha = ref<FichaMedica | null>(null)
const ninoSeleccionado = ref<string>('')
const mensajeError = ref<string | null>(null)

const puedeVer = computed((): boolean => puede('abre_fichas_medicas'))

const ninosSinFicha = computed((): Nino[] => ninos.value.filter((nino) => !nino.tiene_ficha_medica))

onMounted(async () => {
    if (!puedeVer.value) {
        return
    }

    try {
        const respuesta = await llamarApi<{ ninos: Nino[] }>('GET', 'ninos?solo_activos=1')

        ninos.value = respuesta.ninos
    } catch (error) {
        mensajeError.value = mensajeLegible(error)
    }
})

watch(ninoSeleccionado, async (nuevo) => {
    ficha.value = null
    mensajeError.value = null

    if (nuevo === '') {
        return
    }

    try {
        const respuesta = await llamarApi<{ ficha: FichaMedica | null }>(
            'GET',
            `ninos/${nuevo}/ficha-medica`
        )

        ficha.value = respuesta.ficha
    } catch (error) {
        mensajeError.value = mensajeLegible(error)
    }
})
</script>