<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expedient_type_id')->constrained();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('position');
            $table->timestamps();
            $table->index(['expedient_type_id', 'position', 'id']);
        });

        Schema::create('task_template_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_template_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('position');
            $table->timestamps();
            $table->index(['task_template_id', 'position', 'id'], 'task_template_checklist_order_index');
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->collation('utf8mb4_0900_ai_ci')->unique();
            $table->char('color', 7)->charset('ascii')->collation('ascii_bin');
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE tags
            ADD CONSTRAINT tags_name_check
                CHECK (CHAR_LENGTH(name) > 0 AND NOT REGEXP_LIKE(name, '^[[:space:]]|[[:space:]]$')),
            ADD CONSTRAINT tags_color_check
                CHECK (REGEXP_LIKE(color, '^#[0-9a-fA-F]{6}$', 'c'))
            SQL);

        Schema::create('expedient_tag', function (Blueprint $table) {
            $table->foreignId('expedient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['expedient_id', 'tag_id']);
        });

        Schema::create('expedient_user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expedient_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('position')->nullable();
            $table->unsignedBigInteger('column_version')->nullable();
            $table->boolean('hidden')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'expedient_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE expedient_user_preferences ADD CONSTRAINT expedient_user_preferences_state_check CHECK (
                (position IS NULL) = (column_version IS NULL) AND hidden IN (0, 1)
            )
            SQL);

        Schema::create('seeded_catalogs', function (Blueprint $table) {
            $table->string('catalog', 64)->primary();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seeded_catalogs');
        Schema::dropIfExists('expedient_user_preferences');
        Schema::dropIfExists('expedient_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('task_template_checklist_items');
        Schema::dropIfExists('task_templates');
    }
};
