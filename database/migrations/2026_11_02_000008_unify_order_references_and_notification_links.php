<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

return new class extends Migration {
    public function up(): void
    {
        // The internal public_id and merchant_reference remain untouched.
        DB::table('orders')->orderBy('id')->chunkById(200, function ($orders) {
            foreach ($orders as $order) {
                $date = Carbon::parse($order->created_at, 'UTC')->timezone('Asia/Hebron')->format('dmY');
                DB::table('orders')->where('id', $order->id)->update([
                    'display_reference' => 'MTJ-'.$date.'-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT),
                ]);
            }
        });
        Schema::table('delivery_notifications', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->change();
            $table->string('target_url', 500)->nullable();
        });
    }
    public function down(): void
    {
        // Never roll back references to prevent broken external references.
        Schema::table('delivery_notifications', fn (Blueprint $t) => $t->dropColumn('target_url'));
    }
};
