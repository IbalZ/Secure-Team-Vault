<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_accesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vault_credential_id')
                ->constrained('vault_credentials')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->boolean('can_view_metadata')->default(true);
            $table->boolean('can_reveal_secret')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_manage_access')->default(false);

            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['vault_credential_id', 'user_id']);
            $table->index(['user_id', 'revoked_at', 'expires_at'], 'credential_access_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_accesses');
    }
};
