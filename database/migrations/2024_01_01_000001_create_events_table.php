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
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('total_tickets')->unsigned();
            $table->integer('available_tickets')->unsigned();
            $table->decimal('price', 10, 2);
            $table->timestamp('event_date');
            $table->timestamps();
            
            // Indexes for performance
            $table->index('event_date');
            
            // Database constraints to ensure data integrity
            // Available tickets cannot be negative
            $table->check('available_tickets >= 0');
            // Available tickets cannot exceed total tickets
            $table->check('available_tickets <= total_tickets');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};

