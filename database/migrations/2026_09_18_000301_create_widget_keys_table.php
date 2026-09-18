<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widget_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Public by necessity — it sits in the page source of the customer's app.
            // Everything downstream treats it as hostile input.
            $table->string('public_key')->unique();

            $table->jsonb('allowed_origins')->default('[]');
            $table->string('mode')->default('identified');   // identified|anonymous
            $table->boolean('require_email')->default(false);
            $table->boolean('capture_screenshot')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_keys');
    }
};
