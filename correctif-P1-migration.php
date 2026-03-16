<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_history_emails', function (Blueprint $table) {
            $table->index(['contact_id', 'step_id'], 'qhe_contact_step_idx');
        });

        Schema::table('analytics_events', function (Blueprint $table) {
            $table->index(['sent_email_id', 'name'], 'ae_sent_email_name_idx');
        });

        Schema::table('sent_emails', function (Blueprint $table) {
            $table->index(['contact_id', 'created_at'], 'se_contact_created_idx');
            $table->index(['contact_id', 'step_id'], 'se_contact_step_idx');
        });

        Schema::table('queue_history_emails', function (Blueprint $table) {
            $table->index(['contact_id', 'date_email'], 'qhe_contact_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('queue_history_emails', function (Blueprint $table) {
            $table->dropIndex('qhe_contact_step_idx');
            $table->dropIndex('qhe_contact_date_idx');
        });

        Schema::table('analytics_events', function (Blueprint $table) {
            $table->dropIndex('ae_sent_email_name_idx');
        });

        Schema::table('sent_emails', function (Blueprint $table) {
            $table->dropIndex('se_contact_created_idx');
            $table->dropIndex('se_contact_step_idx');
        });
    }
};
