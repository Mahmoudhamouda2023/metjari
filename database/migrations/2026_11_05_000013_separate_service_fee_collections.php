<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->boolean('service_fee_workflow')->default(false);
            $t->unsignedBigInteger('agreed_fee_minor')->nullable();
        });
        Schema::table('financial_entries', function (Blueprint $t) {
            $t->boolean('fee_collection_required')->default(false);
            $t->string('service_fee_payer', 12)->nullable();
            $t->timestamp('fee_collected_at')->nullable();
        });
        // Open orders without financial history can use the new workflow safely.
        DB::table('orders')->whereNotIn('status', ['delivered', 'returned', 'cancelled'])
            ->whereNull('coupon_id')->whereNull('delivery_credit_id')->whereNull('subscription_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('financial_entries')->whereColumn('financial_entries.order_id', 'orders.id'))
            ->update(['service_fee_workflow' => true]);
        // Prior cash receipts cannot be inferred. Backfill only an unpaid attempt fee.
        DB::table('orders')->where('service_fee_workflow', true)
            ->whereIn('status', ['failed', 'office_pending', 'office_received', 'return_assigned', 'return_picked_up', 'return_transit'])
            ->orderBy('id')->chunkById(100, function ($orders) {
                foreach ($orders as $order) {
                    DB::table('financial_entries')->insert([
                        'order_id' => $order->id, 'entry_kind' => 'failed_delivery',
                        'store_id' => $order->store_id, 'captain_id' => $order->captain_id,
                        'outcome' => 'failed', 'fee_collection_required' => true, 'service_fee_payer' => 'recipient',
                        'collected_minor' => 0, 'merchant_received_minor' => 0, 'merchant_net_minor' => 0,
                        'fee_minor' => $order->fee_minor, 'captain_pay_minor' => $order->captain_pay_minor,
                        'fee_collected_at' => (int) $order->fee_minor === 0 ? now() : null,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (DB::table('financial_entries')->where('fee_collection_required', true)->exists()) {
            throw new RuntimeException('Cannot remove the service fee ledger after financial activity.');
        }
        Schema::table('financial_entries', fn (Blueprint $t) => $t->dropColumn(['fee_collection_required', 'service_fee_payer', 'fee_collected_at']));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['service_fee_workflow', 'agreed_fee_minor']));
    }
};
