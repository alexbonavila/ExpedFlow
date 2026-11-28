<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expedients', function (Blueprint $table): void {
            $table->string('samba_provisioning', 66)->charset('ascii')->collation('ascii_bin')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('expedients', function (Blueprint $table): void {
            $table->dropColumn('samba_provisioning');
        });
    }
};
