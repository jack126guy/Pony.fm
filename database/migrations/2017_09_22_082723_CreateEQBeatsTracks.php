<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateEQBeatsTracks extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('eqbeats_tracks', function(Blueprint $table)
        {
            $table->increments('id');
            $table->integer('track_id')->unsigned()->index();
            $table->string('path')->index();
            $table->string('filename')->index();
            $table->string('extension')->index();
            $table->dateTime('imported_at');
            $table->text('parsed_tags');
            $table->text('raw_tags');
        });

        Schema::table('eqbeats_tracks', function(Blueprint $table)
        {
            $table->foreign('track_id')->references('id')->on('tracks')->onUpdate('RESTRICT')->onDelete('RESTRICT');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('eqbeats_tracks', function(Blueprint $table)
        {
            $table->dropForeign('eqbeats_tracks_track_id_foreign');
        });

        Schema::drop('eqbeats_tracks');
    }
}
