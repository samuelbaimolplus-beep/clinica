{{-- Vista de account-result. Blade escapa datos; la interfaz usa los endpoints autorizados de Laravel. --}}
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Verificación de correo · Clínica Vida Salud</title>@include('partials.theme-head')@vite(['resources/css/app.css'])</head>
<body><div class="page-tools">@include('partials.theme-toggle')</div><main class="login-wrap"><section class="panel"><h1>Clínica Vida Salud</h1><p>{{ $message }}</p><a href="/">Volver al inicio de sesión</a></section></main></body></html>
