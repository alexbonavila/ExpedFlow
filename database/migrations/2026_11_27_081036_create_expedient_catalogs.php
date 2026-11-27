<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name')->collation('utf8mb4_0900_ai_ci')->unique();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE clients ADD CONSTRAINT clients_name_check
            CHECK (CHAR_LENGTH(name) > 0 AND NOT REGEXP_LIKE(name, '^[[:space:]]|[[:space:]]$'))
            SQL);

        Schema::create('expedient_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->char('code', 2)->charset('ascii')->collation('ascii_bin')->unique();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE expedient_types ADD CONSTRAINT expedient_types_code_check
            CHECK (REGEXP_LIKE(code, '^[0-9]{2}$', 'c'))
            SQL);

        Schema::create('expedient_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedSmallInteger('last_number')->default(0);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE expedient_sequences ADD CONSTRAINT expedient_sequences_range_check
            CHECK (year BETWEEN 1000 AND 9999 AND last_number BETWEEN 0 AND 999)
            SQL);

        Schema::create('statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('name_translations')->nullable();
            $table->unsignedBigInteger('position');
            $table->timestamps();
            $table->index(['position', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statuses');
        Schema::dropIfExists('expedient_sequences');
        Schema::dropIfExists('expedient_types');
        Schema::dropIfExists('clients');
    }
};
