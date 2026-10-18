<template>
    <div>
        <h1>Retiro de un niño</h1>

        <!--
        PASO 1: BUSCAR AL NIÑO.

        Se busca por apellido, porque en la puerta es lo que se conoce. Y el
        niño aparece con su nombre COMPLETO y su edad: cuando hay dos niños con
        el mismo nombre, la edad es lo que los distingue.
        -->
        <section v-if="nino === null" class="tarjeta">
            <label class="etiqueta" for="buscar">Buscar al niño</label>

            <input
                id="buscar"
                v-model="busqueda"
                type="search"
                class="campo"
                placeholder="Escriba el apellido o el nombre"
                autocomplete="off"
                @keyup.enter="buscar"
            >

            <button
                type="button"
                class="boton boton-principal"
                style="margin-top: 0.75rem; width: 100%;"
                :disabled="cargando"
                @click="buscar"
            >
                {{ cargando ? 'Buscando...' : 'Buscar' }}
            </button>

            <p v-if="errorBusqueda !== null" class="aviso aviso-error">
                {{ errorBusqueda }}
            </p>

            <table v-if="resultados.length > 0" class="tabla" style="margin-top: 1.25rem;">
                <thead>
                    <tr>
                        <th scope="col">Niño</th>
                        <th scope="col">Edad</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in resultados" :key="item.id">
                        <td>{{ item.nombre }}</td>
                        <td>{{ item.edad }} años</td>
                        <td>
                            <button
                                type="button"
                                class="boton"
                                @click="elegirNino(item)"
                            >
                                Elegir
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else-if="busqueda.length >= 2" class="ayuda">
                No hay coincidencias. Revise el apellido, o escríbalo completo.
            </p>
        </section>

        <!--
        PASO 2: LA PANTALLA DEL RETIRO.

        Tres cosas a la vista: el niño, el responsable autorizado y la hora. Y si
        la autorización está vencida, un CARTEL ROJO del tamaño de la pantalla.
        -->
        <template v-else>
            <!--
            EL CARTEL.

            Esto es lo más grande de la aplicación a propósito. La pantalla se usa
            de pie, con un adulto al lado y a veces con más de uno esperando. Un
            "vence el 12 de marzo" en letra chica, al pie de un formulario, se
            pasa inadvertido, y pasar eso por inadvertido es que un niño salga de
            la guardería sin autorización.
            -->
            <div v-if="estado !== null && !estado.permite_retiro" class="cartel-vencido">
                <h2>{{ estado.titulo }}</h2>
                <p>{{ estado.detalle }}</p>
            </div>

            <div v-else-if="estado !== null && estado.por_vencer" class="estado estado-aviso">
                <span>{{ estado.titulo }}</span>
                <span>{{ estado.detalle }}</span>
            </div>

            <div v-else-if="estado !== null" class="estado estado-vigente">
                <span>{{ estado.titulo }}</span>
                <span>{{ estado.detalle }}</span>
            </div>

            <div class="pantalla-retiro">
                <!-- EL NIÑO -->
                <section class="nino-destacado">
                    <h2>{{ nino.nombre }}</h2>

                    <p style="color: var(--tinta-suave); margin: 0 0 1rem;">
                        {{ nino.edad }} años
                        <span v-if="nino.fecha_nacimiento !== null">
                            · nacido el {{ formatearFecha(nino.fecha_nacimiento) }}
                        </span>
                    </p>

                    <!--
                    LA HORA.

                    Es la hora del SERVIDOR, no la del reloj de la pantalla. Un
                    reloj desfasado en el navegador anotaría una hora que el
                    registro de responsabilidad no puede sostener.
                    -->
                    <p class="ayuda" style="margin-bottom: 0.25rem;">
                        Hora del servidor, que es la que queda registrada
                    </p>
                    <p class="hora-retiro">{{ horaDelServidor }}</p>

                    <p v-if="horaServidorDesfazada" class="estado estado-aviso">
                        El reloj de esta pantalla marca {{ horaDelNavegador }}.
                        Se registra la hora del servidor.
                    </p>

                    <button type="button" class="boton" @click="volverABuscar">
                        Cambiar de niño
                    </button>
                </section>

                <!-- EL RESPONSABLE -->
                <section>
                    <h2>¿Quién viene a buscarlo?</h2>

                    <!--
                    LOS AUTORIZADOS PRIMERO.

                    El caso normal va arriba, sin que haya que buscarlo. Los que
                    NO pueden vienen abajo, apartados y tachados: están porque
                    llegan a la puerta, y porque la persona tiene que poder ver
                    POR QUÉ no puede.
                    -->
                    <template v-if="autorizados.length > 0">
                        <button
                            v-for="persona in autorizados"
                            :key="persona.id"
                            type="button"
                            class="responsable"
                            :class="{ 'responsable-elegido': elegido === persona.id }"
                            @click="elegirResponsable(persona)"
                        >
                            <span>
                                <strong>{{ persona.nombre }}</strong>
                                <br>
                                <small style="color: var(--tinta-suave);">
                                    {{ persona.parentesco ?? 'Responsable' }}
                                    · {{ persona.documento }}
                                </small>
                            </span>
                            <span class="pildora pildora-ok">Autorizado</span>
                        </button>
                    </template>

                    <template v-if="noAutorizados.length > 0">
                        <h3 style="margin-top: 1.5rem; font-size: 1rem; color: var(--tinta-suave);">
                            No pueden retirar a este niño
                        </h3>

                        <button
                            v-for="persona in noAutorizados"
                            :key="persona.id"
                            type="button"
                            class="responsable responsable-bloqueado"
                            disabled
                        >
                            <span>
                                <strong>{{ persona.nombre }}</strong>
                                <br>
                                <small>{{ persona.parentesco ?? 'Responsable' }} · {{ persona.documento }}</small>
                            </span>
                            <span
                                class="pildora"
                                :class="persona.estado === 'no_autorizado' ? 'pildora-neutra' : 'pildora-vencida'"
                            >
                                {{ persona.estado === 'no_autorizado' ? 'Sin autorización' : 'Vencida' }}
                            </span>
                        </button>
                    </template>

                    <div v-if="responsables.length === 0" class="vacio">
                        <strong>Este niño no tiene responsables registrados</strong>
                        Pida a dirección que registre a sus responsables antes.
                        Sin responsables registrados no hay nadie a quien se le
                        pueda retirar.
                    </div>

                    <p class="ayuda" style="margin-top: 1.25rem;">
                        Tocar a un responsable autorizado registra el retiro con su
                        nombre, la hora y el documento con que se identificó.
                    </p>
                </section>
            </div>

            <!--
            EL AVISO DE LO QUE SE ESTÁ APOINTANDO DE DECIR.
            -->
            <section v-if="mensajeError !== null" class="aviso aviso-error">
                <strong>No se registró el retiro.</strong>
                <p style="margin: 0.4rem 0 0;">{{ mensajeError }}</p>
            </section>

            <section v-if="ultimoRetiro !== null" class="tarjeta">
                <h2>Retiro registrado</h2>

                <p>
                    <strong>{{ ultimoRetiro.nino?.nombre }}</strong> fue retirado
                    por <strong>{{ ultimoRetiro.responsable_nombre }}</strong>
                    a las <strong>{{ ultimoRetiro.hora }}</strong>.
                </p>

                <p style="color: var(--tinta-suave); font-size: 0.9rem;">
                    Documento: {{ ultimoRetiro.responsable_documento }}.
                    <template v-if="ultimoRetiro.operador !== null">
                        Registrado por {{ ultimoRetiro.operador }}.
                    </template>
                </p>

                <button type="button" class="boton boton-principal" @click="volverABuscar">
                    Registrar otro retiro
                </button>
            </section>
        </template>
    </div>
</template>

<script setup lang="ts">
/**
 * LA PANTALLA DE RETIRO. La más importante de la aplicación.
 *
 * QUÉ TIENE QUE VER LA PERSONA QUE OPERA ESTA PANTALLA
 * ---------------------------------------------------
 * Al niño. Al responsable autorizado. La hora.
 *
 * Y si la autorización está vencida, el sistema lo dice EN GRANDE, no en letra
 * chica. Esa última parte no es estilo: es que el caso raro es el grave, y si
 * el aviso se pasa inadvertido, el niño sale con alguien que no debía
 * llevárselo.
 *
 * LO QUE LA PANTALLA NO DECIDE
 * ---------------------------
 * No decide si el retiro está autorizado. Esa pregunta la hace la API, y la
 * respuesta llega calculada desde `EvaluadorDeAutorizacion`.
 *
 * Que la pantalla no decida es lo que hace que el botón no se pueda usar para
 * eludir la regla: aunque alguien falsee la vista desde el navegador, la API
 * igual rechaza el retiro y deja el registro.
 *
 * Ver docs/02 y docs/07.
 */
import type {
    EstadoDeAutorizacion,
    Nino,
    PantallaDeRetiro,
    ResponsableParaRetiro,
    Retiro
} from '~/tipos/api'

definePageMeta({
    middleware: 'auth'
})

useHead({ title: 'Retiro' })

const busqueda = ref<string>('')
const resultados = ref<Nino[]>([])
const cargando = ref<boolean>(false)
const errorBusqueda = ref<string | null>(null)

const nino = ref<PantallaDeRetiro['nino'] | null>(null)
const responsables = ref<ResponsableParaRetiro[]>([])
const horaDelServidor = ref<string>('')

const elegido = ref<string | null>(null)
const estado = ref<EstadoDeAutorizacion | null>(null)

const mensajeError = ref<string | null>(null)
const ultimoRetiro = ref<Retiro | null>(null)

/** Los que PUEDEN, arriba. Los que no, abajo y apartados. */
const autorizados = computed((): ResponsableParaRetiro[] =>
    responsables.value.filter((persona) => persona.permite_retiro)
)

const noAutorizados = computed((): ResponsableParaRetiro[] =>
    responsables.value.filter((persona) => !persona.permite_retiro)
)

/**
 * La hora del navegador, sólo para avisar si no coincide con la del servidor.
 */
const horaDelNavegador = ref<string>('')

const horaServidorDesfazada = computed((): boolean => {
    if (horaDelServidor.value === '' || horaDelNavegador.value === '') {
        return false
    }

    return horaDelServidor.value !== horaDelNavegador.value
})

async function buscar(): Promise<void> {
    if (busqueda.value.trim().length < 2) {
        resultados.value = []
        return
    }

    cargando.value = true
    errorBusqueda.value = null

    try {
        const respuesta = await llamarApi<{ ninos: Nino[] }>(
            'GET',
            `ninos?buscar=${encodeURIComponent(busqueda.value.trim())}&solo_activos=1`
        )

        resultados.value = respuesta.ninos
    } catch (error) {
        errorBusqueda.value = mensajeLegible(error)
    } finally {
        cargando.value = false
    }
}

async function elegirNino(elegidoNino: Nino): Promise<void> {
    cargando.value = true
    errorBusqueda.value = null
    mensajeError.value = null
    ultimoRetiro.value = null
    elegido.value = null
    estado.value = null

    try {
        const respuesta = await llamarApi<PantallaDeRetiro>(
            'GET',
            `ninos/${elegidoNino.id}/retiro`
        )

        nino.value = respuesta.nino
        responsables.value = respuesta.responsables
        horaDelServidor.value = respuesta.hora
        horaDelNavegador.value = new Date().toTimeString().slice(0, 5)
    } catch (error) {
        errorBusqueda.value = mensajeLegible(error)
    } finally {
        cargando.value = false
    }
}

/**
 * Tocar a un responsable REGISTRA el retiro.
 *
 * Es una sola acción a propósito. Pedir "elija responsable" y después "confirme"
 * agrega un paso a una pantalla que se usa apurada, y un paso de más es un
 * paso de más en el que alguien aprieta lo que sea.
 *
 * Y si la API rechaza el retiro, se muestra el motivo en grande. El rechazo
 * también queda registrado: eso lo hace la API, no esta pantalla.
 */
async function elegirResponsable(persona: ResponsableParaRetiro): Promise<void> {
    if (!persona.permite_retiro || nino.value === null) {
        return
    }

    elegido.value = persona.id
    mensajeError.value = null
    ultimoRetiro.value = null

    try {
        const respuesta = await llamarApi<{ retiro: Retiro }>(
            'POST',
            `ninos/${nino.value.id}/retiros`,
            {
                responsable_id: persona.id,
                autorizacion_id: persona.autorizacion_id
            }
        )

        ultimoRetiro.value = respuesta.retiro

        // Se vuelve a preguntar el estado, porque el retiro recién registrado
        // cambia la foto: la autorización ya se usó y el niño ya salió.
        await recargarEstado(persona)
    } catch (error) {
        mensajeError.value = mensajeLegible(error)
        elegido.value = null
    }
}

/**
 * Vuelve a preguntar el estado de la autorización para quien se acaba de ir.
 */
async function recargarEstado(persona: ResponsableParaRetiro): Promise<void> {
    if (nino.value === null) {
        return
    }

    try {
        estado.value = await llamarApi<EstadoDeAutorizacion>(
            'GET',
            `ninos/${nino.value.id}/retiros/estado/${persona.id}`
        )
    } catch {
        // Si esta consulta falla, la pantalla ya tiene lo que necesita: el retiro
        // quedó registrado. No es un motivo para tapar ese resultado.
        estado.value = null
    }
}

function volverABuscar(): void {
    nino.value = null
    responsables.value = []
    resultados.value = []
    elegido.value = null
    estado.value = null
    mensajeError.value = null
    ultimoRetiro.value = null
    busqueda.value = ''
}
</script>