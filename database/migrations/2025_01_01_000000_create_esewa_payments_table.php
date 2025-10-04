<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('esewa_payments', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_uuid')->unique();
            $table->string('product_code');
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('service_charge')->default(0);
            $table->unsignedBigInteger('delivery_charge')->default(0);
            $table->unsignedBigInteger('total_amount');

            $table->string('status')->default('PENDING');
            $table->string('ref_id')->nullable();
            $table->timestamp('verified_at')->nullable();

            $table->json('raw_response')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->index(['transaction_uuid', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esewa_payments');
    }
};
