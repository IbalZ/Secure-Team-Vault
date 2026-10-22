<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_credentials', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->string('category')->nullable()->index();
            $table->string('website_url', 2048)->nullable();

            // All recoverable secret fields live inside one AES-256-GCM payload.
            $table->longText('encrypted_payload');
            $table->string('nonce', 24);
            $table->string('auth_tag', 24);
            $table->unsignedSmallInteger('key_version')->default(1);
            $table->unsignedSmallInteger('payload_version')->default(1);

            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_credentials');
    }
};
