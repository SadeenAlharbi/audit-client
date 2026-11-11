<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('audit-client.outbox.connection');
    }

    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('external_id', 191)->nullable()->index();
            $table->longText('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return (string) config('audit-client.outbox.table', 'audit_outbox');
    }
};
