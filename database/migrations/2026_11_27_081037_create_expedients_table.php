<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expedients', function (Blueprint $table) {
            $table->id();
            $table->char('code', 9)->charset('ascii')->collation('ascii_bin')->unique();
            $table->unsignedSmallInteger('year');
            $table->unsignedSmallInteger('sequence_number');
            $table->foreignId('expedient_type_id')->constrained();
            $table->foreignId('requester_client_id')->constrained('clients');
            $table->foreignId('represented_client_id')->nullable()->constrained('clients');
            $table->foreignId('status_id')->constrained();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('column_position');
            $table->unsignedBigInteger('column_version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['year', 'sequence_number']);
            $table->foreign('year')->references('year')->on('expedient_sequences');
            $table->index(['status_id', 'deleted_at', 'column_position', 'id'], 'expedients_status_order_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE expedients
            ADD CONSTRAINT expedients_code_check CHECK (
                REGEXP_LIKE(code, '^[0-9]{9}$', 'c')
                AND sequence_number BETWEEN 1 AND 999
                AND LEFT(code, 7) = CONCAT(year, LPAD(sequence_number, 3, '0'))
            ),
            ADD CONSTRAINT expedients_distinct_clients_check CHECK (
                represented_client_id IS NULL OR represented_client_id <> requester_client_id
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('expedients');
    }
};
