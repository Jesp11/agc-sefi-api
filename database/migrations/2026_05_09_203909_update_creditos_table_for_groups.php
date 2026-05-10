<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('creditos', function (Blueprint $table) {
            $table->string('id_cliente')->nullable()->change();
            $table->unsignedBigInteger('id_grupo')->nullable()->after('id_cliente');
            $table->enum('tipo_credito', ['Individual', 'Grupal'])->default('Individual')->after('id_grupo');

            $table->foreign('id_grupo')->references('id')->on('grupos')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('creditos', function (Blueprint $table) {
            $table->dropForeign(['id_grupo']);
            $table->dropColumn(['id_grupo', 'tipo_credito']);
            $table->string('id_cliente')->nullable(false)->change();
        });
    }
};
