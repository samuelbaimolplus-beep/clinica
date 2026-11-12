<?php

/** Evolución versionada del esquema. Ejecute migraciones sobre la conexión elegida; nunca use migrate:fresh en la base principal. */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Aplica el cambio de esquema correspondiente a esta migración. */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->bigInteger('provider_amount_minor')->nullable();
            $t->string('provider_currency', 3)->nullable();
            $t->decimal('exchange_rate', 18, 6)->nullable();
            $t->text('approval_url')->nullable();
            $t->string('capture_id', 100)->nullable();
        });
        DB::statement('ALTER TABLE public.pagos ADD CONSTRAINT pagos_captura_proveedor_id_unico UNIQUE (captura_proveedor_id)');
        DB::statement("ALTER TABLE public.pagos ADD CONSTRAINT pagos_snapshot_paypal_validacion CHECK (metodo <> 'paypal' OR (monto_proveedor_centavos IS NOT NULL AND monto_proveedor_centavos > 0 AND moneda_proveedor IS NOT NULL AND moneda_proveedor = 'USD' AND tasa_bob_por_usd IS NOT NULL AND tasa_bob_por_usd > 0))");
    }

    /** Describe la reversión de esta migración; se utiliza únicamente al solicitar un rollback. */
    public function down(): void
    {
        if (DB::table('payments')->where('method', 'paypal')->exists()) {
            throw new RuntimeException('Existen operaciones PayPal; no se elimina su trazabilidad.');
        }
        DB::statement('ALTER TABLE public.pagos DROP CONSTRAINT pagos_snapshot_paypal_validacion');
        DB::statement('ALTER TABLE public.pagos DROP CONSTRAINT pagos_captura_proveedor_id_unico');
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn(['provider_amount_minor', 'provider_currency', 'exchange_rate', 'approval_url', 'capture_id']));
    }
};
