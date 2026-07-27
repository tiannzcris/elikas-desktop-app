<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evacuees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('suffix', 10)->nullable();
            $table->enum('sex', ['male', 'female']);
            $table->date('date_of_birth');
            $table->string('civil_status', 20)->nullable();
            $table->string('contact_number', 20)->nullable();
            $table->boolean('is_pwd')->default(false);
            $table->string('pwd_type', 100)->nullable();
            $table->boolean('is_pregnant')->default(false);
            $table->boolean('is_lactating')->default(false);
            $table->boolean('is_solo_parent')->default(false);
            $table->boolean('is_indigenous_person')->default(false);
            $table->boolean('is_4ps_beneficiary')->default(false);
            $table->boolean('is_head_of_family')->default(false);

            $table->unsignedBigInteger('remote_id')->nullable();
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evacuees');
    }
};
