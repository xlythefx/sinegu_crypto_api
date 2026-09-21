<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Done-state for Admin → To be Done (the owner's list of things only they can
 * do or decide: register at a third party, paste a key, pick a role name).
 *
 * The ITEMS are not here. They are authored in the frontend repo
 * (`src/lib/adminTodos.ts`) by whoever builds the feature that leaves the
 * work, shipped and reviewed with it. This table only remembers, per item
 * slug, whether the owner ticked it off and the note they left — the note is
 * where a decision's outcome gets recorded. Server-side rather than browser
 * storage because the owner reads the page on a phone and a laptop and both
 * must agree. A row whose slug no longer exists in the list is harmless and is
 * simply not shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_todo_states', function (Blueprint $table) {
            $table->string('slug', 80)->primary();
            $table->dateTime('done_at')->nullable();
            $table->string('done_by', 36)->nullable()->comment('user_credentials.uni_id of the admin who ticked it');
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_todo_states');
    }
};
