# Código de la clínica en español

Las clases del dominio, controladores, servicios y símbolos propios de la interfaz utilizan nombres en español. Por ejemplo:

| Nombre anterior | Nombre actual |
| --- | --- |
| `User.php` | `app/Models/Usuario.php` |
| `Doctor.php` | `app/Models/Medico.php` |
| `Patient.php` | `app/Models/Paciente.php` |
| `Appointment.php` | `app/Models/Cita.php` |
| `AuthController.php` | `ControladorAutenticacion.php` dentro de CU01 |
| `LogoutController.php` | `ControladorCerrarSesion.php` dentro de CU02 |
| `modules/clinical.js` | `resources/js/modules/acciones-clinicas.js` |
| `modules/appointment-workspace.js` | `resources/js/modules/area-citas.js` |
| `lib/navigation.js` | `resources/js/lib/navegacion.js` |

## Flujo de cancelar cita

1. `resources/js/modules/area-citas.js`: genera el botón y llama a `interfaz.accionesClinicas.cancelar(cita)`.
2. `resources/js/modules/acciones-clinicas.js`: `cancelar()` solicita la causa y el motivo; `formularioOperacion()` envía la petición.
3. `routes/web.php`: `POST /api/appointments/{id}/cancel` llega a `ControladorAccionesCita::ejecutarAccion()`.
4. `app/Paquetes/P05_CitasMedicas/Compartido/Http/ControladorAccionesCita.php`: autoriza y valida los datos.
5. `app/Paquetes/P05_CitasMedicas/Compartido/Servicios/ServicioCitas.php`: `cambiarEstado()` valida la cita, guarda el estado Cancelada y registra el historial y la auditoría en una transacción.
6. `app/Models/Cita.php`: representa la cita persistida. La respuesta vuelve al formulario, que actualiza la pantalla.

La reprogramación usa el mismo módulo de interfaz, mediante `reprogramar()`, y se dirige a `ControladorReprogramacion::reprogramar()` y después a `ServicioCitas::programar()`.

## Contratos que conservan su nombre técnico

Se mantienen las palabras reservadas de PHP y JavaScript, los métodos y propiedades exigidos por Laravel y Eloquent (`handle`, `casts`, `$table`, etc.), las relaciones serializadas, los campos y rutas de la API existente, las claves de configuración y los campos de PayPal. Las dependencias instaladas conservan su código original.

Los modelos declaran sus tablas y las claves de relaciones que dependen de las convenciones de nombres. La fábrica de usuarios declara su modelo explícitamente. La auditoría conserva sus identificadores históricos aunque las clases PHP se hayan renombrado. La traducción no requiere una migración de los datos.
