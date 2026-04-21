<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_dates', function (Blueprint $table) {
            $table->string('service_id');
            $table->date('date');
            $table->unsignedTinyInteger('exception_type');

            $table->primary(['service_id', 'date']);

            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_dates');
    }
};
