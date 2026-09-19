<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-project custom fields.
     *
     * The largest single gap against MantisBT, and the one an agency asks for first:
     * "Browser", "Environment", "Client reference". Per project rather than per
     * workspace because an agency's clients do not share a vocabulary — the field
     * that matters on a shop is not the one that matters on an internal tool.
     *
     * Values live in their own table rather than a JSON column on issues. Filtering
     * and sorting by a field's value is most of why anyone wants custom fields, and
     * JSON containment across a table this size is the kind of query that is fine in
     * development and not in production. It also means deleting a field takes its
     * values with it by foreign key rather than by a migration nobody writes.
     */
    public function up(): void
    {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // What a person sees.
            $table->string('name', 60);

            // What the query language, the CSV header and the API use. Stable when
            // the name is edited, which is the whole reason it is a separate column:
            // renaming "Browser" to "Browser version" must not break a saved view.
            $table->string('key', 60);

            $table->string('type', 20);

            // Choices for a select. Null for every other type.
            $table->json('options')->nullable();

            $table->boolean('required')->default(false);

            /*
             * Whether a client can see this field at all.
             *
             * Defaults to false, like comments and activity events, because the rule
             * throughout is that something a client can see is a decision and never
             * an accident. "Internal estimate" and "Client reference" are both
             * plausible custom fields and only one of them should ever be shown.
             */
            $table->boolean('visible_to_client')->default(false);

            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            // Two fields keyed "browser" on one project makes the query language
            // ambiguous and the export wrong.
            $table->unique(['project_id', 'key']);
            $table->index(['workspace_id', 'project_id', 'position']);
        });

        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete();

            // Text whatever the type. The type decides how it is validated on the way
            // in and rendered on the way out; storing seven columns to avoid one cast
            // would be worse.
            $table->text('value')->nullable();

            $table->timestamps();

            $table->unique(['issue_id', 'custom_field_id']);

            /*
             * Indexed by field, not by (field, value).
             *
             * Postgres btree entries cap at about 2.7KB, and a multiline field allows
             * 5000 characters — so including the value would have thrown "index row
             * size exceeds maximum" on insert, for long values only. Every test here
             * uses short ones, so it would have passed and then failed on somebody's
             * pasted stack trace.
             *
             * Filtering narrows by field first, and one field's values on one
             * workspace is a small enough set to scan.
             */
            $table->index('custom_field_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_fields');
    }
};
