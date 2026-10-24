<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_occurrences', fn (Blueprint $t) => $t->char('occurrence_month', 7)->nullable());
        DB::table('expense_occurrences')->update(['occurrence_month' => DB::raw("DATE_FORMAT(due_on, '%Y-%m')")]);
        Schema::table('expense_occurrences', function (Blueprint $t) {
            $t->char('occurrence_month', 7)->nullable(false)->change();
            $t->unique(['template_id', 'occurrence_month'], 'expense_template_month_unique');
        });
    }

    public function down(): void
    {
        Schema::table('expense_occurrences', function (Blueprint $t) {
            $t->dropUnique('expense_template_month_unique');
            $t->dropColumn('occurrence_month');
        });
    }
};
