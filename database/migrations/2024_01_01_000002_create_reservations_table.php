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
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['reserved', 'purchased', 'expired', 'cancelled'])
                ->default('reserved');
            $table->timestamp('expires_at');
            $table->timestamp('purchased_at')->nullable();
            $table->timestamps();
            
            // Critical indexes for performance
            // For finding reservations by event and status
            $table->index(['event_id', 'status']);
            
            // For finding user's reservations
            $table->index(['user_id', 'status']);
            
            // Critical index for expired reservation cleanup job
            $table->index('expires_at');
            
            // Composite index for checking existing user reservations
            $table->index(['event_id', 'user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};

