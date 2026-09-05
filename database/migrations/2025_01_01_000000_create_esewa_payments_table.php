<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create($this->table(), function (Blueprint $table) {
            $table->id();

            $table->string('transaction_uuid')->unique();
            $table->string('product_code')->index();

            // Rupees with paisa. Decimal rather than integer so 1234.50 survives
            // the round trip, and decimal rather than float so it survives it
            // exactly.
            $table->decimal('amount', 12, 2);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('service_charge', 12, 2)->default(0);
            $table->decimal('delivery_charge', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);

            $table->string('status')->default('PENDING')->index();
            $table->string('ref_id')->nullable()->index();
            $table->timestamp('verified_at')->nullable();

            // What this payment is for. String id so applications keyed by UUID
            // or ULID work without a second migration.
            $table->string('payable_type')->nullable();
            $table->string('payable_id')->nullable();

            $table->json('raw_response')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['payable_type', 'payable_id'], 'esewa_payments_payable_index');
            $table->index(['status', 'created_at'], 'esewa_payments_reconcile_index');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config('esewa.database.table', 'esewa_payments');
    }

    private function schema(): \Illuminate\Database\Schema\Builder
    {
        return Schema::connection(config('esewa.database.connection'));
    }
};
