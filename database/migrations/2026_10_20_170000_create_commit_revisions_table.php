<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commits', function (Blueprint $table): void {
            $table->timestamp('amended_at')->nullable();
        });

        Schema::create('commit_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('commit_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->json('mentions')->nullable();
            $table->timestamp('created_at');
        });
    }
};
