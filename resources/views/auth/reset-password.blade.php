{{-- Vista de reset-password. Blade escapa datos; la interfaz usa los endpoints autorizados de Laravel. --}}
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Recuperar contraseña · Clínica Vida Salud</title>@include('partials.theme-head')@vite(['resources/css/app.css', 'resources/js/lib/password-visibility.js'])</head>
<body><div class="page-tools">@include('partials.theme-toggle')</div><main class="login-wrap"><section class="panel"><h1>Recuperar contraseña</h1><form id="reset-form">
@csrf
<input type="hidden" name="token" value="{{ $token }}"><input type="hidden" name="email" value="{{ request('email') }}">
@foreach (['password' => 'Nueva contraseña', 'password_confirmation' => 'Repetir contraseña'] as $name => $label)
<div class="field"><label for="{{ $name }}">{{ $label }}</label><div class="password-control">
<input id="{{ $name }}" name="{{ $name }}" type="password" minlength="10" maxlength="128" autocomplete="new-password" required>
<button type="button" class="password-toggle" data-password-toggle aria-controls="{{ $name }}" aria-label="Mostrar contraseña" aria-pressed="false" title="Mostrar contraseña"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button>
</div></div>
@endforeach
<p id="reset-message" role="status"></p><button class="btn success" type="submit">Restablecer contraseña</button>
</form><p><a href="/">Volver al inicio de sesión</a></p></section></main>
<script>
document.querySelector('#reset-form').addEventListener('submit', async event => {
    event.preventDefault(); const form = event.currentTarget; const button = form.querySelector('button[type="submit"]'); button.disabled = true;
    try {
        const response = await fetch('/api/account/reset', {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': form.elements._token.value}, body: JSON.stringify(Object.fromEntries(new FormData(form)))});
        const result = await response.json();
        document.querySelector('#reset-message').textContent = result.errors ? Object.values(result.errors).flat().join(' ') : result.message;
        if (response.ok) form.reset(); else button.disabled = false;
    } catch { document.querySelector('#reset-message').textContent = 'No se pudo completar la operación. Intente nuevamente.'; button.disabled = false; }
});
</script></body></html>
