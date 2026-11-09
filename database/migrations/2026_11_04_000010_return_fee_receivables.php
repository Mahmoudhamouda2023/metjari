<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('return_fee_receivables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders');
            $table->string('payer', 20);
            $table->unsignedBigInteger('goods_due_minor')->default(0);
            $table->unsignedBigInteger('fee_due_minor');
            $table->unsignedBigInteger('collected_minor')->default(0);
            $table->timestamp('collected_at')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('return_fee_receivables'); }
};
