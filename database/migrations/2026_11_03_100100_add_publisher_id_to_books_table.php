<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            // Nullable: older books and self-published titles may have no known publisher.
            // nullOnDelete: removing a publisher must not remove its books — they stay in
            // the warehouse and keep their history, only the link becomes empty.
            $table->foreignId('publisher_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropConstrainedForeignId('publisher_id');
        });
    }
};
