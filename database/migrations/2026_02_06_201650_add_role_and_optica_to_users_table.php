<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // ✅ debe coincidir con roles.id (unsignedTinyInteger)
            $table->unsignedTinyInteger('role_id')->after('id');

            // ✅ opticas.id es BIGINT (id()), esto sí es correcto
            $table->unsignedBigInteger('optica_id')->nullable()->after('role_id');

            $table->boolean('active')->default(true)->after('remember_token');

            $table->index('role_id');
            $table->index('optica_id');

            $table->foreign('role_id')->references('id')->on('roles');
            $table->foreign('optica_id')->references('id')->on('opticas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropForeign(['optica_id']);
            $table->dropIndex(['role_id']);
            $table->dropIndex(['optica_id']);
            $table->dropColumn(['role_id', 'optica_id', 'active']);
        });
    }
};