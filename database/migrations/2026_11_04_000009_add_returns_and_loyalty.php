<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tariffs', fn (Blueprint $t) => $t->unsignedSmallInteger('return_delivery_share_bps')->default(5000));
        Schema::table('orders', function (Blueprint $t) {
            $t->unsignedSmallInteger('return_delivery_share_bps')->default(5000);
            $t->unsignedInteger('loyalty_points')->default(0);
        });
        Schema::create('return_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('captain_id')->nullable()->constrained('staff')->restrictOnDelete();
            $t->foreignId('assigned_by')->nullable()->constrained('staff')->restrictOnDelete();
            $t->foreignId('received_by')->nullable()->constrained('staff')->restrictOnDelete();
            foreach (['office_handed_at', 'office_received_at', 'assigned_at', 'picked_up_at', 'departed_at', 'completed_at'] as $column) {
                $t->timestamp($column)->nullable();
            }
            $t->timestamps();
        });
        Schema::table('financial_entries', function (Blueprint $t) {
            $t->string('entry_kind', 20)->default('delivery');
            $t->index('order_id', 'financial_entry_order_lookup');
        });
        Schema::table('financial_entries', function (Blueprint $t) {
            $t->dropUnique(['order_id']);
            $t->unique(['order_id', 'entry_kind'], 'financial_entry_order_kind');
        });
        Schema::create('loyalty_settings', function (Blueprint $t) {
            $t->id();
            $t->boolean('enabled')->default(false);
            $t->unsignedInteger('points_per_order')->default(2);
            $t->unsignedInteger('reward_points')->default(10);
            $t->unsignedSmallInteger('coupon_days')->default(30);
            $t->boolean('include_free_orders')->default(false);
            $t->timestamps();
        });
        DB::table('loyalty_settings')->insert(['id' => 1, 'enabled' => false, 'points_per_order' => 2, 'reward_points' => 10, 'coupon_days' => 30, 'include_free_orders' => false, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('loyalty_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->unique()->constrained()->restrictOnDelete();
            $t->bigInteger('balance')->default(0);
            $t->unsignedBigInteger('reserved')->default(0);
            $t->timestamps();
        });
        Schema::create('reward_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->uuid('submission_key');
            $t->unsignedInteger('points');
            $t->unsignedSmallInteger('coupon_days');
            $t->string('status', 12)->default('pending');
            $t->foreignId('coupon_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('staff')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->string('review_note', 500)->nullable();
            $t->timestamps();
            $t->unique(['store_id', 'submission_key']);
            $t->index(['status', 'id']);
        });
        Schema::create('loyalty_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('reward_request_id')->nullable()->constrained()->restrictOnDelete();
            $t->bigInteger('points');
            $t->string('reason', 240);
            $t->timestamps();
            $t->index(['store_id', 'id']);
            $t->index('order_id');
        });
        Schema::table('delivery_notifications', function (Blueprint $t) {
            $t->unsignedBigInteger('staff_id')->nullable()->change();
            $t->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->index(['user_id', 'read_at', 'id']);
        });
    }

    public function down(): void
    {
        // Refuse destructive rollback once this feature has operational data.
        if (DB::table('return_tasks')->exists() || DB::table('loyalty_entries')->exists() || DB::table('reward_requests')->exists()) {
            throw new RuntimeException('This migration contains return/reward history. Restore a verified backup instead of rolling it back.');
        }
        DB::table('delivery_notifications')->whereNull('staff_id')->delete();
        Schema::table('delivery_notifications', function (Blueprint $t) {
            $t->dropForeign(['user_id']);
            $t->dropIndex(['user_id', 'read_at', 'id']);
            $t->dropColumn('user_id');
            $t->unsignedBigInteger('staff_id')->nullable(false)->change();
        });
        foreach (['loyalty_entries', 'reward_requests', 'loyalty_accounts', 'loyalty_settings', 'return_tasks'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('financial_entries', function (Blueprint $t) {
            $t->dropUnique('financial_entry_order_kind');
            $t->unique('order_id');
            $t->dropColumn('entry_kind');
        });
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['return_delivery_share_bps', 'loyalty_points']));
        Schema::table('tariffs', fn (Blueprint $t) => $t->dropColumn('return_delivery_share_bps'));
    }
};
