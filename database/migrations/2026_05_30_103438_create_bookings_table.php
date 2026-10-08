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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('show_id');
            $table->unsignedBigInteger('movie_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('show_id')->references('id')->on('movie_shows')->onDelete('restrict');
            $table->foreign('movie_id')->references('id')->on('movies')->onDelete('restrict');
            $table->uuid('code')->unique();
            $table->enum('status', [
                'pending',
                'reserved',
                'payment_pending',
                'confirmed',
                'cancelled',
                'failed',
            ])->default('pending');
            $table->decimal('total_amount', 8, 2)->default(0);
            $table->dateTime('booked_at');
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index(['user_id', 'status'], 'bookings_user_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
