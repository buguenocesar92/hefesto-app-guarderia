<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Rutas de consola
|------------------------------------------------------------------------------
|
| Los comandos de Guardería se descubren automáticamente desde
| app/Console/Commands. Se dejan declarados aquí para que quede explícito qué
| hay y qué no.
|
| EL QUE HAY QUE CORRER ANTES DE RECIBIR AL PRIMER NIÑO
| ----------------------------------------------------
| `guarderia:verificar-aislamiento` comprueba, desde el servidor y sin pasar por
| la aplicación, que las catorce tablas de negocio tienen Row Level Security
| forzado, que la función de contexto es `SECURITY INVOKER`, que la vista
| pública expone tres columnas y que una consulta SIN contexto no lee ni una
| fila.
|
| Esa última comprobación es la que importa. Las otras dicen cómo se instaló la
| protección; ésa dice si FUNCIONA.
|
| Una instalación en la que este comando no se ha corrido NO está verificada.
| Ver docs/03 y docs/04.
|
| EL QUE IMPORTA CORRER A DIARIO
| -----------------------------
| `guarderia:autorizaciones-por-vencer` lista las autorizaciones que entran en
| la ventana de aviso, ANTES de que expiren. Un sistema que avisa cuando ya
| venció no está avisando: está dejando constancia.
|
| OJO: en esta versión NO envía correo. La lista queda en la salida del comando
| y en el registro del servidor. Agregar el envío es trabajo pendiente, anotado
| en docs/10.
|
| LO QUE NO HAY
| -------------
| No hay ninguna tarea programada que borre ficha de un menor, ni asistencia, ni
| registro de retiros. No hay tarea que purgue nada.
|
| Los plazos de conservación están en `null` porque no se verificó ninguno, y en
| `null` el sistema no borra nada por su cuenta. La razón está en docs/07.
|
*/