<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expedients', function (Blueprint $table) {
            $table->string('samba_relative_path', 1024)->nullable();
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expedient_id')->nullable()->constrained()->nullOnDelete();
            $table->char('expedient_code', 9)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->string('type', 64);
            $table->string('operation', 64);
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['expedient_code', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE incidents ADD CONSTRAINT incidents_expedient_code_check
            CHECK (expedient_code IS NULL OR REGEXP_LIKE(expedient_code, '^[0-9]{9}$', 'c'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');

        Schema::table('expedients', function (Blueprint $table) {
            $table->dropColumn('samba_relative_path');
        });
    }
};
