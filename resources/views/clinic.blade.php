<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Gestión de citas médicas de Clínica Vida Salud">
    <title>Clínica Vida Salud · Gestión de citas</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <header class="topbar">
        <button class="mobile-toggle icon-btn" id="menu-toggle" aria-label="Abrir menú" aria-expanded="false">☰</button>
        <a class="brand" href="/" aria-label="Clínica Vida Salud, inicio"><span class="brand-mark">✚</span><span><strong>Clínica Vida Salud</strong><small>Gestión de citas médicas</small></span></a>
        <div id="profile" class="profile"></div>
    </header>
    <div class="shell"><aside id="sidebar" aria-label="Navegación principal" hidden></aside><main id="main" tabindex="-1"><p class="empty">Cargando aplicación…</p></main></div>
    <dialog id="modal" aria-labelledby="modal-title"><div class="dialog-top"><h2 id="modal-title"></h2><button class="icon-btn" id="close-modal" aria-label="Cerrar">×</button></div><div id="modal-body"></div></dialog>
    <div id="toasts" role="status" aria-live="polite"></div>
    <noscript>Habilite JavaScript para gestionar las citas médicas.</noscript>
</body>
</html>
