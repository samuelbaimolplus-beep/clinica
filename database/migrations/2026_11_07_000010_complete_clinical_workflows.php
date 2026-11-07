<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('specialties', fn (Blueprint $t) => $t->unsignedInteger('fee_minor')->nullable());
        Schema::create('booking_requests', function (Blueprint $t) {
            $t->id();
            $t->uuid('idempotency_key')->unique();
            $t->foreignId('patient_id')->constrained()->restrictOnDelete();
            $t->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $t->foreignId('specialty_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->date('date');
            $t->time('start_time');
            $t->time('end_time');
            $t->string('modality', 20);
            $t->unsignedInteger('amount_minor');
            $t->string('status', 20)->default('held');
            $t->unsignedSmallInteger('attempt')->default(0);
            $t->timestampTz('expires_at');
            $t->timestampTz('deadline_at');
            $t->timestamps();
            $t->index(['doctor_id', 'date', 'status']);
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_request_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedSmallInteger('attempt')->default(1);
            $t->string('method', 30);
            $t->unsignedInteger('amount_minor');
            $t->string('status', 20);
            $t->string('reference')->nullable()->unique();
            $t->string('bank_reference')->nullable()->unique();
            $t->text('qr_image')->nullable();
            $t->string('qr_number')->nullable();
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('confirmed_at')->nullable();
            $t->timestamps();
            $t->unique(['booking_request_id', 'attempt']);
        });
        Schema::table('appointments', function (Blueprint $t) {
            $t->foreignId('booking_request_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('parent_appointment_id')->nullable()->constrained('appointments')->restrictOnDelete();
            $t->string('modality', 20)->default('presential');
            $t->boolean('complimentary')->default(false);
            $t->string('cancellation_cause', 20)->nullable();
            $t->timestampTz('cancelled_at')->nullable();
            $t->timestampTz('missed_call_at')->nullable();
            $t->timestampTz('attended_at')->nullable();
            $t->timestamp('followup_suggested_at')->nullable();
            $t->text('followup_note')->nullable();
        });
        Schema::table('agenda_adjustments', fn (Blueprint $t) => $t->foreignId('appointment_id')->nullable()->constrained()->restrictOnDelete());
        DB::statement('ALTER TABLE agenda_adjustments DROP CONSTRAINT agenda_adjustment_values');
        DB::statement("ALTER TABLE agenda_adjustments ADD CONSTRAINT agenda_adjustment_values CHECK (start_time < end_time AND kind IN ('presential_block','online_opening') AND status IN ('active','released','consumed') AND ((kind = 'presential_block' AND specialty_id IS NOT NULL) OR (kind = 'online_opening' AND specialty_id IS NULL)))");
        foreach (['doctor_id', 'patient_id'] as $column) {
            DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_{$column}_no_overlap EXCLUDE USING gist (int8range({$column}, {$column}, '[]') WITH &&, tsrange(date + start_time, date + end_time, '[)') WITH &&) WHERE (status IN ('Programada','Confirmada'))");
        }
        Schema::create('reschedule_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('appointment_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('status', 20)->default('pending');
            $t->text('reason');
            $t->text('response')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('resolved_at')->nullable();
            $t->timestamps();
        });
        DB::statement("CREATE UNIQUE INDEX reschedule_one_pending ON reschedule_requests (appointment_id) WHERE status = 'pending'");
        Schema::create('refund_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('appointment_id')->constrained()->restrictOnDelete();
            $t->foreignId('payment_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('status', 20);
            $t->unsignedInteger('amount_minor');
            $t->text('reason');
            $t->text('decision_reason')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('resolved_at')->nullable();
            $t->string('executor')->nullable();
            $t->string('method', 80)->nullable();
            $t->string('reference')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migración clínica con trazabilidad: no se elimina historial mediante rollback.');
    }
};
