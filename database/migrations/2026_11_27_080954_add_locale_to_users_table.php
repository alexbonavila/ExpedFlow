<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('locale', 2)->charset('ascii')->collation('ascii_bin')->default('es');
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_locale_check CHECK (locale IN ('es', 'ca', 'en'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CHECK users_locale_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
