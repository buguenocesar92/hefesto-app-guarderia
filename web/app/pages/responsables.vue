<template>
    <div>
        <h1>Responsables</h1>

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
                placeholder="Apellido, nombre o número de documento"
                autocomplete="off"
                @keyup.enter="buscar"
            >

            <p class="ayuda">
                En la puerta se busca por nombre. En el archivo, por número de
                documento.
            </p>

            <table v-if="responsables.length > 0" class="tabla" style="margin-top: 1rem;">
                <thead>
                    <tr>
                        <th scope="col">Responsable</th>
                        <th scope="col">Documento</th>
                        <th scope="col">Niños</th>
                        <th scope="col">Puede retirar hoy</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="persona in responsables" :key="persona.id">
                        <td>
                            <strong>{{ persona.nombre }}</strong>
                            <br>
                            <small style="color: var(--tinta-suave);">
                                {{ persona.telefono ?? 'Sin teléfono' }}
                            </small>
                        </td>

                        <td>{{ persona.documento }}</td>

                        <td>
                            <span
                                v-for="nino in persona.ninos ?? []"
                                :key="nino.id"
                                style="display: block;"
                            >
                                {{ nino.nombre }}
                                <small style="color: var(--tinta-suave);">
                                    ({{ nino.parentesco ?? 'responsable' }})
                                </small>
                            </span>

                            <span
                                v-if="(persona.ninos ?? []).length === 0"
                                class="pildora pildora-vencida"
                            >
                                Sin niños asignados
                            </span>
                        </td>

                        <!--
                        EL ESTADO DE HOY, POR NIÑO.

                        Sale de la API y se calcula con la fecha del servidor.
                        NO es un campo guardado en la base: un campo que dice si
                        un adulto puede llevarse a un niño es de los que no pueden
                        estar equivocados ni desactualizados.
                        -->
                        <td>
                            <span
                                v-for="autorizacion in persona.autorizaciones ?? []"
                                :key="autorizacion.nino_id"
                                class="pildora"
                                :class="pildoraDe(autorizacion)"
                                style="display: block; margin-bottom: 0.25rem; width: fit-content;"
                            >
                                {{ etiquetaDe(autorizacion) }}
                            </span>

                            <span
                                v-if="(persona.autorizaciones ?? []).length === 0"
                                class="pildora pildora-neutra"
                            >
                                Sin autorización registrada
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else-if="!cargando" class="ayuda">
                No hay responsables que coincidan con la búsqueda.
            </p>
        </section>
    </div>
</template>

<script setup lang="ts">
/**
 * La lista de responsables.
 *
 * LA COLUMNA QUE IMPORTA
 * ----------------------
 * "Puede retirar hoy", por niño. Es la pregunta que se hace en la puerta, con
 * la persona al lado, y la respuesta tiene que estar a la vista antes de que
 * alguien la pregunte.
 *
 * Y sale de la API, que es quien tiene la fecha del servidor. Si esta pantalla
 * calculara la vigencia con el reloj del navegador, un reloj desfasado
 * autorizaría o rechazaría a la persona equivocada.
 *
 * Ver docs/02 y docs/03.
 */
import type { AutorizacionDeResponsable, Responsable } from '~/tipos/api'

definePageMeta({
    middleware: 'auth'
})

useHead({ title: 'Responsables' })

const responsables = ref<Responsable[]>([])
const busqueda = ref<string>('')
const cargando = ref<boolean>(false)
const mensajeError = ref<string | null>(null)

onMounted(async () => {
    await cargar()
})

async function cargar(): Promise<void> {
    cargando.value = true
    mensagemError.value = null

    try {
        const consulta = busqueda.value.trim().length >= 2
            ? `?buscar=${encodeURIComponent(busqueda.value.trim())}&solo_activos=1`
            : '?solo_activos=1'

        const respuesta = await llamarApi<{ responsables: Responsable[] }>(
            'GET',
            `responsables${consulta}`
        )

        responsables.value = respuesta.responsables
    } catch (error) {
        mensajeError.value = mensajeLegible(error)
    } finally {
        cargando.value = false
    }
}

async function buscar(): Promise<void> {
    await cargar()
}

function pildoraDe(autorizacion: AutorizacionDeResponsable): string {
    if (autorizacion.permite_retiro) {
        // Próxima a vencer: ámbar, no rojo. Aún hay tiempo, pero hay que
        // avisar. Avisar antes de que expire es lo que la aplicación promete.
        return autorizacion.dias_para_vencer !== null && autorizacion.dias_para_vencer <= 15
            ? 'pildora-aviso'
            : 'pildora-ok'
    }

    return 'pildora-vencida'
}

function etiquetaDe(autorizacion: AutorizacionDeResponsable): string {
    if (!autorizacion.permite_retiro) {
        return autorizacion.estado === 'anulada' ? 'Anulada' : 'Vencida'
    }

    if (autorizacion.vigente_hasta === null) {
        return 'Autorizado'
    }

    if (autorizacion.dias_para_vencer !== null && autorizacion.dias_para_vencer <= 15) {
        return `Vence en ${autorizacion.dias_para_vencer} días`
    }

    return `Hasta el ${formatearFecha(autorizacion.vigente_hasta)}`
}
</script>