<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The central roster cache behind the removed Evacuees page. This app is
    // for offline data entry; viewing the online roster is the web
    // dashboard's job. Registered families (this device's own records) is
    // the app's only household listing now.
    public function up(): void
    {
        Schema::dropIfExists('evacuee_records');
    }

    public function down(): void
    {
        Schema::create('evacuee_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('remote_id')->unique();
            $table->string('head_name', 150)->nullable();
            $table->string('barangay_name', 100)->nullable();
            $table->unsignedInteger('member_count')->default(0);
            $table->timestamps();
        });
    }
};
