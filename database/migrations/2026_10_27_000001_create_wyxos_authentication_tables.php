<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wyxos_authentication_identities', function (Blueprint $table) {
            $table->id();
            $table->char('subject_key', 64)->unique();
            $table->string('guard', 64);
            $table->string('email', 254)->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
            $table->index(['guard', 'email']);
        });
        Schema::create('wyxos_authentication_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('identity_id')->constrained('wyxos_authentication_identities')->cascadeOnDelete();
            $table->char('fingerprint', 64);
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['identity_id', 'fingerprint']);
            $table->index('last_seen_at');
        });
        Schema::create('wyxos_authentication_log', function (Blueprint $table) {
            $table->id();
            $table->morphs('authenticatable', 'wyxos_auth_log_actor_index');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->char('device_id', 64)->nullable();
            $table->string('device_name')->nullable();
            $table->boolean('is_trusted')->default(false);
            $table->timestamp('login_at');
            $table->boolean('login_successful')->default(true);
            $table->timestamp('logout_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->boolean('cleared_by_user')->default(false);
            $table->json('location')->nullable();
            $table->boolean('is_suspicious')->default(false);
            $table->string('suspicious_reason')->nullable();
            $table->boolean('new_device');
            $table->string('notification_status', 20);
            $table->index(['login_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wyxos_authentication_log');
        Schema::dropIfExists('wyxos_authentication_devices');
        Schema::dropIfExists('wyxos_authentication_identities');
    }
};
