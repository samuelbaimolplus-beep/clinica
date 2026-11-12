import {defineConfig} from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    fullyParallel: false,
    workers: 1,
    reporter: 'list',
    outputDir: 'storage/app/private/browser-results',
    use: {
        baseURL: 'http://127.0.0.1:8011',
        channel: process.platform === 'win32' ? 'msedge' : undefined,
        headless: true,
        screenshot: 'only-on-failure',
        viewport: {width: 1440, height: 1000},
        launchOptions: {args: ['--disable-gpu', '--no-zygote']},
    },
    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=8011',
        url: 'http://127.0.0.1:8011',
        reuseExistingServer: false,
        timeout: 30000,
        env: {APP_ENV:'testing', SESSION_DRIVER:'array', CACHE_STORE:'array', MAIL_MAILER:'array', PAYPAL_ENABLED:'false', DB_DATABASE:'clinica_vida_salud_test'},
    },
});
