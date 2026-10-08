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
        Schema::create('booking_seats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->unsignedBigInteger('show_seat_id');
            $table->decimal('price_paid', 8, 2);
            $table->enum('status', [
                'pending',
                'confirmed',
                'cancelled',
            ]);
            $table->timestamps();

            $table->foreign('booking_id')
                ->references('id')
                ->on('bookings')
                ->onDelete('cascade');
            $table->foreign('show_seat_id')
                ->references('id')
                ->on('show_seats')
                ->onDelete('restrict');
            $table->unique(
                ['booking_id', 'show_seat_id']
            );


            DB::statement("CREATE UNIQUE INDEX booking_seats_one_active_holder
               ON booking_seats (show_seat_id) WHERE status IN ('pending','confirmed')");
        });
    }   

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_seats');
    }
};
