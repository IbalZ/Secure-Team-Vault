<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('staff')->after('password')->index();
            $table->boolean('is_active')->default(true)->after('role')->index();
            $table->timestamp('account_expires_at')->nullable()->after('is_active')->index();
            $table->timestamp('last_login_at')->nullable()->after('account_expires_at');
            $table->foreignId('created_by')
                ->nullable()
                ->after('last_login_at')
                ->constrained('users')
                ->nullOnDelete();
        });

        // Promote only the known bootstrap account. If it is not present, no other
        // account is promoted implicitly.
        DB::table('users')
            ->where('email', 'admin@secureteamvault.test')
            ->update([
                'role' => 'admin',
                'is_active' => true,
                'account_expires_at' => null,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn([
                'role',
                'is_active',
                'account_expires_at',
                'last_login_at',
                'created_by',
            ]);
        });
    }
};
