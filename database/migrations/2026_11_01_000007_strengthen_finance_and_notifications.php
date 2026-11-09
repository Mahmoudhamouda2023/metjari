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
            $t->string('display_reference', 180)->nullable()->unique();
            // Snapshot policy: older orders retain their agreed prices.
            $t->boolean('return_policy_v2')->default(false);
        });
        DB::table('orders')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $name = DB::table('stores')->where('id', $row->store_id)->value('name');
                DB::table('orders')->where('id', $row->id)->update(['display_reference' => mb_substr($name ?? 'متجر', 0, 120).' #'.$row->id]);
            }
        });
        Schema::table('expenses', fn (Blueprint $t) => $t->uuid('submission_key')->nullable()->unique());
        foreach (['settlements', 'captain_payouts', 'remittances'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestamp('voided_at')->nullable();
                $t->unsignedBigInteger('voided_by')->nullable();
                $t->string('void_reason', 500)->nullable();
            });
        }
        Schema::create('financial_batch_items', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 16);
            $t->unsignedBigInteger('batch_id');
            $t->foreignId('financial_entry_id')->constrained()->restrictOnDelete();
            $t->json('snapshot');
            $t->unique(['kind', 'batch_id', 'financial_entry_id'], 'financial_batch_item_unique');
        });
        DB::table('financial_entries')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                foreach (['remittance' => 'remittance_id', 'settlement' => 'settlement_id', 'captain' => 'captain_payout_id'] as $kind => $column) {
                    if ($row->$column) {
                        DB::table('financial_batch_items')->insert(['kind' => $kind, 'batch_id' => $row->$column, 'financial_entry_id' => $row->id, 'snapshot' => json_encode((array) $row, JSON_THROW_ON_ERROR)]);
                    }
                }
            }
        });
        Schema::create('delivery_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('staff_id')->constrained('staff')->restrictOnDelete();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->string('title', 240);
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->index(['staff_id', 'read_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_notifications');
        Schema::dropIfExists('financial_batch_items');
        foreach (['settlements', 'captain_payouts', 'remittances'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('voided_by');
                $t->dropColumn(['voided_at', 'void_reason']);
            });
        }
        Schema::table('expenses', function (Blueprint $t) {
            $t->dropUnique(['submission_key']);
            $t->dropColumn('submission_key');
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->dropUnique(['display_reference']);
            $t->dropColumn(['display_reference', 'return_policy_v2']);
        });
    }
};
