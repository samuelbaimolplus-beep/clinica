import {defineConfig} from '@playwright/test';
export default defineConfig({
 testDir:'./tests/BrowserReal',workers:1,fullyParallel:false,timeout:45000,
 globalSetup:'./tests/BrowserReal/setup.mjs',
 outputDir:'storage/app/private/browser-real-results',
 reporter:[['list'],['json',{outputFile:'storage/app/private/browser-real-results.json'}]],
 use:{baseURL:'http://127.0.0.1:8012',channel:'msedge',headless:true,viewport:{width:1440,height:1000},screenshot:'only-on-failure'},
 webServer:{command:'php -S 127.0.0.1:8012 -t public tests/BrowserReal/router.php',url:'http://127.0.0.1:8012',reuseExistingServer:false,
 env:{APP_ENV:'local',APP_DEBUG:'false',APP_URL:'http://127.0.0.1:8012',DB_DATABASE:'clinica_vida_salud_browser_test',DB_URL:'',SESSION_DRIVER:'file',SESSION_COOKIE:'clinica_browser_test',SESSION_DOMAIN:'',SESSION_SECURE_COOKIE:'false',CACHE_STORE:'file',MAIL_MAILER:'array',PAYPAL_ENABLED:'true',PAYPAL_CLIENT_ID:'browser-simulation',PAYPAL_CLIENT_SECRET:'browser-simulation',PAYPAL_BOB_PER_USD:'10',CLINIC_APPOINTMENTS_ENABLED:'true'}}
});
