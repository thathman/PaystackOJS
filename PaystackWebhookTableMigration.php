<?php

/**
 * @file plugins/paymethod/paystack/PaystackWebhookTableMigration.php
 *
 * Copyright (c) 2025 Hendrix Nwaokolo, Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PaystackWebhookTableMigration
 *
 * @brief Creates the webhook audit-log, webhook dedupe, and fulfillment-guard tables.
 */

namespace APP\plugins\paymethod\paystack;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class PaystackWebhookTableMigration extends Migration
{
    public function up(): void
    {
        // Create webhook payloads table for auditing
        if (!Schema::hasTable('paystack_webhook_logs')) {
            Schema::create('paystack_webhook_logs', function (Blueprint $table) {
                $table->bigIncrements('webhook_log_id');
                $table->bigInteger('context_id')->nullable();
                $table->string('event', 100);
                $table->text('payload');
                $table->boolean('verified')->default(false);
                $table->text('error')->nullable();
                $table->string('reference', 255)->nullable();
                $table->string('transaction_id', 255)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['context_id', 'event']);
                $table->index(['reference']);
            });
        }

        // Webhook event dedupe (replaces unbounded plugin_settings keys)
        if (!Schema::hasTable('paystack_webhook_dedupe')) {
            Schema::create('paystack_webhook_dedupe', function (Blueprint $table) {
                $table->bigIncrements('dedupe_id');
                $table->bigInteger('context_id');
                $table->string('event', 100);
                $table->string('reference', 128);
                $table->timestamp('created_at')->useCurrent();
                $table->unique(['context_id', 'event', 'reference'], 'psx_webhook_dedupe_unique');
                $table->index(['created_at'], 'psx_webhook_dedupe_created_idx');
            });
        }

        // Fulfillment guard: prevents the webhook/callback race from
        // fulfilling the same payment twice (unique insert claims the work).
        if (!Schema::hasTable('paystack_fulfillment_guards')) {
            Schema::create('paystack_fulfillment_guards', function (Blueprint $table) {
                $table->bigIncrements('guard_id');
                $table->bigInteger('context_id');
                $table->bigInteger('queued_payment_id')->nullable();
                $table->string('reference', 128)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->unique(['context_id', 'reference'], 'psx_guard_context_reference_unique');
                $table->index(['created_at'], 'psx_guard_created_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('paystack_fulfillment_guards')) {
            Schema::drop('paystack_fulfillment_guards');
        }
        if (Schema::hasTable('paystack_webhook_dedupe')) {
            Schema::drop('paystack_webhook_dedupe');
        }
        if (Schema::hasTable('paystack_webhook_logs')) {
            Schema::drop('paystack_webhook_logs');
        }
    }
}
