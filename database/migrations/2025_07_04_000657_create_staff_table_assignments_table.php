<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_table_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained('reservations')->onDelete('cascade'); // Link to reservation
            $table->foreignId('table_id')->constrained('tables')->onDelete('cascade');
            $table->foreignId('waiter_id')->constrained('staff')->onDelete('cascade');
            $table->foreignId('manager_id')->nullable()->constrained('staff')->onDelete('cascade'); // Nullable if manager is not always assigned
            $table->foreignId('cleaner_id')->nullable()->constrained('staff')->onDelete('cascade'); // Nullable
            $table->date('assignment_date');
            $table->time('assignment_time');
            $table->time('assignment_end_time'); // Added for time range checking
            $table->timestamps();

            // Add unique constraint if a table can only have one assignment for a specific slot
            $table->unique(['table_id', 'assignment_date', 'assignment_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_table_assignments');
    }
};