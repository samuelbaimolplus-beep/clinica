<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Recuperar contraseña · Clínica Vida Salud</title>@vite(['resources/css/app.css'])</head>
<body><main class="login-wrap"><section class="panel"><h1>Recuperar contraseña</h1><form id="reset-form">
@csrf
<input type="hidden" name="token" value="{{ $token }}"><input type="hidden" name="email" value="{{ request('email') }}">
<label class="field">Nueva contraseña<input name="password" type="password" minlength="10" maxlength="128" autocomplete="new-password" required></label>
<label class="field">Repetir contraseña<input name="password_confirmation" type="password" minlength="10" maxlength="128" autocomplete="new-password" required></label>
<p id="reset-message" role="status"></p><button class="btn success" type="submit">Restablecer contraseña</button>
</form><p><a href="/">Volver al inicio de sesión</a></p></section></main>
<script>
document.querySelector('#reset-form').addEventListener('submit', async event => {
    event.preventDefault(); const form = event.currentTarget; const button = form.querySelector('button'); button.disabled = true;
    try {
        const response = await fetch('/api/account/reset', {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': form.elements._token.value}, body: JSON.stringify(Object.fromEntries(new FormData(form)))});
        const result = await response.json();
        document.querySelector('#reset-message').textContent = result.errors ? Object.values(result.errors).flat().join(' ') : result.message;
        if (response.ok) form.reset(); else button.disabled = false;
    } catch { document.querySelector('#reset-message').textContent = 'No se pudo completar la operación. Intente nuevamente.'; button.disabled = false; }
});
</script></body></html>
