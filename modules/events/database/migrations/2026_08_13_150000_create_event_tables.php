<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('status')->default('draft');
            $table->string('venue')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('sales_starts_at')->nullable();
            $table->timestamp('sales_ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('event_performances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('capacity');
            $table->string('venue')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'starts_at']);
        });

        Schema::create('event_ticket_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('performance_id')->nullable()->constrained('event_performances')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('capacity')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'performance_id']);
        });

        Schema::create('event_tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('number');
            $table->string('token', 64)->unique();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('performance_id')->constrained('event_performances')->cascadeOnDelete();
            $table->foreignId('ticket_type_id')->constrained('event_ticket_types')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_email');
            $table->string('customer_name');
            $table->string('status')->default('issued');
            $table->timestamp('checked_in_at')->nullable();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['performance_id', 'status']);
            $table->unique('number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_tickets');
        Schema::dropIfExists('event_ticket_types');
        Schema::dropIfExists('event_performances');
        Schema::dropIfExists('events');
    }
};
