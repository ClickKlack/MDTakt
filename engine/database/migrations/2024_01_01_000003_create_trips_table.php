<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->string('trip_id')->primary();
            $table->string('route_id');
            $table->string('service_id');
            $table->string('block_id')->nullable();
            $table->unsignedTinyInteger('direction_id')->nullable();

            $table->foreign('route_id')->references('route_id')->on('routes');

            $table->index('route_id');
            $table->index('service_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
