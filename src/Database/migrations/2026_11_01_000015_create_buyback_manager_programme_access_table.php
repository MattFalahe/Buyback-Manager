<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who runs each buyback programme and who may watch it, named by an admin.
 *
 * Having a character in a corporation says nothing about running its
 * buyback: a member of the main corporation may run one of their own under
 * an alt corporation, and must not reach the main one's. So each programme names
 * its managers and viewers, as SeAT accounts or whole SeAT roles, and a
 * programme nobody is named on is an admin's alone.
 */
class CreateBuybackManagerProgrammeAccessTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('buyback_manager_programme_access')) {
            return;
        }

        Schema::create('buyback_manager_programme_access', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setting_id');
            $table->string('access', 16);       // manager | viewer
            $table->string('subject_type', 16); // user | role
            $table->unsignedBigInteger('subject_id');
            $table->timestamps();

            $table->unique(['setting_id', 'access', 'subject_type', 'subject_id'], 'bb_programme_access_unique');
            $table->index(['subject_type', 'subject_id'], 'bb_programme_access_subject');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyback_manager_programme_access');
    }
}
