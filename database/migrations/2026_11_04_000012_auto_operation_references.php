<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_reference', 40)->nullable()->unique();
        });

        Schema::table('remittances', function (Blueprint $table) {
            $table->string('system_reference', 40)->nullable()->unique();
            $table->string('review_note', 500)->nullable();
        });

        Schema::table('settlements', function (Blueprint $table) {
            $table->string('system_reference', 40)->nullable()->unique();
        });

        Schema::table('captain_payouts', function (Blueprint $table) {
            $table->string('system_reference', 40)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('captain_payouts', fn (Blueprint $table) => $table->dropUnique(['system_reference']));
        Schema::table('captain_payouts', fn (Blueprint $table) => $table->dropColumn('system_reference'));
        Schema::table('settlements', fn (Blueprint $table) => $table->dropUnique(['system_reference']));
        Schema::table('settlements', fn (Blueprint $table) => $table->dropColumn('system_reference'));
        Schema::table('remittances', fn (Blueprint $table) => $table->dropUnique(['system_reference']));
        Schema::table('remittances', fn (Blueprint $table) => $table->dropColumn(['system_reference', 'review_note']));
        Schema::table('orders', fn (Blueprint $table) => $table->dropUnique(['delivery_reference']));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('delivery_reference'));
    }
};
