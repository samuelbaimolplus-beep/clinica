import {execFileSync} from 'node:child_process';
import {statSync} from 'node:fs';
export default async function(){
 const started=Date.now();
 const env={...process.env,APP_ENV:'local',DB_DATABASE:'clinica_vida_salud_browser_test',DB_URL:'',MAIL_MAILER:'array',PAYPAL_ENABLED:'false'};
 execFileSync('php',['tests/BrowserReal/provision.php'],{env,encoding:'utf8'});
 const output=execFileSync('php',['tests/BrowserReal/seed.php'],{env,encoding:'utf8'});
 if(statSync('storage/app/private/browser-real-fixture.json').mtimeMs<started)throw new Error('No se prepararon los datos: '+output);
}
