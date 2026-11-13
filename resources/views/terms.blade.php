{{-- Vista de terms. Blade escapa datos; la interfaz usa los endpoints autorizados de Laravel. --}}
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Condiciones de la cuenta · Clínica Vida Salud</title>
    @include('partials.theme-head')
    @vite(['resources/css/app.css'])
</head>
<body>
<div class="page-tools">@include('partials.theme-toggle')</div>
<main class="login-wrap">
    <section class="panel terms">
        <h1>Condiciones de la cuenta de paciente</h1>
        <p>Versión {{ config('clinic.terms_version') }} · Entorno académico del proyecto Clínica Vida Salud.</p>
        <h2>Uso de la cuenta</h2>
        <p>La cuenta es opcional, personal y exclusiva para personas con 18 años cumplidos. Se registra presencialmente en la clínica por personal autorizado, únicamente cuando el paciente la solicita y acepta estas condiciones. Debe proporcionar información correcta y conservar su contraseña de forma privada. Para activar su acceso, confirme el código enviado a su correo electrónico.</p>
        <p>La atención no depende de una cuenta: todos los pacientes, incluidos los menores, pueden tener su ficha y recibir atención, citas, cobros y seguimiento mediante Recepción, sin correo electrónico ni acceso al portal.</p>
        <h2>Datos del registro</h2>
        <p>El sistema guarda los datos de identificación y contacto proporcionados, la vinculación de su cuenta y los eventos de auditoría de sus operaciones. Para habilitar el portal, el personal debe verificar su identidad y registrar su aceptación expresa, con fecha y versión de estas condiciones. Registrar una ficha de paciente no supone aceptar estas condiciones ni crear una cuenta.</p>
        <h2>Funciones disponibles</h2>
        <p>Después de activar la cuenta puede gestionar su usuario, contraseña y datos de contacto, consultar disponibilidad e historial y utilizar los flujos de reserva y solicitudes habilitados para pacientes. Cambiar el correo exige confirmarlo con un nuevo código. Crear o activar una cuenta no genera una cita ni una ficha médica.</p>
        <h2>Correcciones y acceso</h2>
        <p>Para corregir sus datos de identidad o resolver una cuenta inactiva, comuníquese con recepción. El personal autorizado administra los registros y conserva su trazabilidad.</p>
        <p><a href="/">Volver al sistema</a></p>
    </section>
</main>
</body>
</html>
