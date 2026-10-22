<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_chain_heads')) {
            Schema::create('audit_chain_heads', function (Blueprint $table): void {
                $table->string('chain_id', 64)->primary();
                $table->uuid('last_event_uuid')->nullable();
                $table->char('last_hash', 64)->nullable();
                $table->unsignedBigInteger('event_count')->default(0);
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->uuid('event_uuid')->unique();
                $table->string('chain_id', 64)->index();
                $table->timestamp('occurred_at', 6)->index();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->string('actor_role', 32)->nullable();
                $table->string('action', 100)->index();
                $table->string('target_type', 100)->nullable();
                $table->string('target_id', 64)->nullable();
                $table->uuid('request_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->char('user_agent_hash', 64)->nullable();
                $table->longText('metadata');
                $table->longText('event_payload');
                $table->char('previous_hash', 64)->nullable();
                $table->char('entry_hash', 64);

                $table->foreign('chain_id')
                    ->references('chain_id')
                    ->on('audit_chain_heads')
                    ->restrictOnDelete();
                $table->index(['target_type', 'target_id']);
            });
        }

        DB::table('audit_chain_heads')->insertOrIgnore([
            'chain_id' => (string) config('vault.audit.chain_id', 'main'),
            'last_event_uuid' => null,
            'last_hash' => null,
            'event_count' => 0,
            'updated_at' => now(),
        ]);

        if (DB::connection()->getDriverName() === 'mysql') {
            try {
                DB::unprepared(<<<'SQL'
                    CREATE TRIGGER audit_logs_prevent_update
                    BEFORE UPDATE ON audit_logs
                    FOR EACH ROW
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Audit log entries are append-only and cannot be updated'
                    SQL);

                DB::unprepared(<<<'SQL'
                    CREATE TRIGGER audit_logs_prevent_delete
                    BEFORE DELETE ON audit_logs
                    FOR EACH ROW
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Audit log entries are append-only and cannot be deleted'
                    SQL);
            } catch (QueryException $exception) {
                if (! str_contains($exception->getMessage(), '1419')) {
                    throw $exception;
                }

                logger()->warning(
                    'MySQL audit triggers were skipped because binary logging requires server-level privileges. Application append-only protections and HMAC verification remain active.',
                );
            }
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_prevent_update');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_prevent_delete');
        }

        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('audit_chain_heads');
    }
};
