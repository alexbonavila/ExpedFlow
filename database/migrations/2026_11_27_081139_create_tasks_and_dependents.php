<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expedient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('status_id')->constrained();
            $table->string('name');
            $table->text('description')->nullable();
            $table->date('scheduled_date')->nullable();
            $table->time('scheduled_time')->nullable();
            $table->unsignedBigInteger('position');
            $table->boolean('deleted_by_expedient')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('active_position')->virtualAs('IF(deleted_at IS NULL, position, NULL)')->nullable();
            $table->unique(['expedient_id', 'status_id', 'active_position'], 'tasks_active_position_unique');
            $table->index(['expedient_id', 'status_id', 'deleted_at', 'position'], 'tasks_status_order_index');
            $table->index(['scheduled_date', 'deleted_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE tasks
            ADD CONSTRAINT tasks_schedule_check CHECK (
                scheduled_time IS NULL
                OR (scheduled_date IS NOT NULL AND scheduled_time BETWEEN '00:00:00' AND '23:59:59')
            ),
            ADD CONSTRAINT tasks_deleted_by_expedient_check CHECK (
                deleted_by_expedient = 0 OR (deleted_by_expedient = 1 AND deleted_at IS NOT NULL)
            )
            SQL);

        Schema::create('task_user', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['task_id', 'user_id']);
        });

        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_user_id')->constrained('users');
            $table->text('body');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['task_id', 'deleted_at', 'created_at', 'id'], 'task_comments_history_index');
        });

        Schema::create('task_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('completed')->default(false);
            $table->unsignedBigInteger('position');
            $table->timestamps();
            $table->index(['task_id', 'position', 'id']);
        });

        DB::statement('ALTER TABLE task_checklist_items ADD CONSTRAINT task_checklist_items_completed_check CHECK (completed IN (0, 1))');
    }

    public function down(): void
    {
        Schema::dropIfExists('task_checklist_items');
        Schema::dropIfExists('task_comments');
        Schema::dropIfExists('task_user');
        Schema::dropIfExists('tasks');
    }
};
