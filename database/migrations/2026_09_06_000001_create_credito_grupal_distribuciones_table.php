<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credito_grupal_distribuciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('num_prog');
            // El identificador y los datos del integrante son una fotografía
            // documental. No se usan como una relación con borrado en cascada.
            $table->string('id_cliente');
            $table->string('nombre_cliente');
            $table->string('curp')->nullable();
            $table->string('clave_elector')->nullable();
            $table->string('telefono')->nullable();
            $table->text('direccion')->nullable();
            $table->decimal('capital', 12, 2);
            $table->decimal('interes', 12, 2);
            $table->decimal('total', 12, 2);
            $table->decimal('valor_ficha', 12, 2);
            $table->unsignedInteger('orden');
            $table->string('folio_documental');
            $table->timestamps();

            $table->foreign('num_prog')->references('num_prog')->on('creditos')->onDelete('cascade');
            $table->unique(['num_prog', 'id_cliente']);
            $table->unique(['num_prog', 'orden']);
            $table->index(['num_prog', 'folio_documental']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_grupal_distribuciones');
    }
};
