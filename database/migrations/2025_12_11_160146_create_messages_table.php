<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMessagesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * Server stores only encrypted payloads and encrypted AES keys.
     */
    public function up()
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('sender_id')->index();
            $table->unsignedBigInteger('receiver_id')->index();

            // encrypted message body (base64 or binary encoded into text)
            $table->text('body')->nullable();

            // encrypted attachment path (file encrypted client-side)
            $table->string('attachment')->nullable();
            $table->string('mime')->nullable();

            // encryption metadata for AES-GCM
            $table->string('enc_algo')->default('aes-256-gcm');
            $table->string('iv')->nullable();    // base64 iv/nonce
            $table->string('tag')->nullable();   // base64 auth tag (if separate)

            // encrypted AES keys for recipient(s). JSON mapping: device_id -> encrypted_key_base64
            $table->json('encrypted_keys')->nullable();

            // any metadata (e.g., filename) — recommended to be encrypted client-side in meta blob
            $table->json('meta')->nullable();

            $table->boolean('is_read')->default(false);
            $table->timestamps();

            $table->foreign('sender_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('receiver_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('messages');
    }
}
