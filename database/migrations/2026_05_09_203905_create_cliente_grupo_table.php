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
        Schema::create('cliente_grupo', function (Blueprint $table) {
            $table->id();
            $table->string('id_cliente');
            $table->unsignedBigInteger('id_grupo');
            $table->timestamps();

            $table->foreign('id_cliente')->references('id_cliente')->on('clientes')->onDelete('cascade');
            $table->foreign('id_grupo')->references('id')->on('grupos')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cliente_grupo');
    }
};
