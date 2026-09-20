<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laundry_orders', function (Blueprint $table) {
            $table->foreign('tablet_order_id')->references('id')->on('tablet_orders')->nullOnDelete();
        });

        Schema::table('tablet_orders', function (Blueprint $table) {
            $table->string('status')->default('pending')->change();
            $table->string('payment_method')->default('room_charge')->change();
            $table->string('payment_status')->default('unpaid')->change();
        });
    }

    public function down(): void
    {
        Schema::table('laundry_orders', function (Blueprint $table) {
            $table->dropForeign(['tablet_order_id']);
        });

        Schema::table('tablet_orders', function (Blueprint $table) {
            $table->string('status')->change();
            $table->string('payment_method')->change();
            $table->string('payment_status')->change();
        });
    }
};
