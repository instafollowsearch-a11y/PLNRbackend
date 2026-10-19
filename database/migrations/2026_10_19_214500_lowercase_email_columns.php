<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $columns = [
        'users' => 'email',
        'plan_shares' => 'invitee_email',
        'plan_sessions' => 'recipient_email',
        'weekend_pick_reminders' => 'recipient_email',
        'itinerary_stop_reminders' => 'recipient_email',
        'password_reset_tokens' => 'email',
    ];

    public function up(): void
    {
        $this->resolveUserEmailConflicts();

        foreach ($this->columns as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $driver = DB::getDriverName();

            if (in_array($driver, ['pgsql', 'sqlite'], true)) {
                DB::statement("UPDATE {$table} SET {$column} = LOWER(TRIM({$column})) WHERE {$column} IS NOT NULL AND {$column} <> LOWER(TRIM({$column}))");

                continue;
            }

            DB::table($table)
                ->whereNotNull($column)
                ->get([$column])
                ->each(function (object $row) use ($table, $column): void {
                    $current = (string) ($row->{$column} ?? '');
                    $normalized = mb_strtolower(trim($current));

                    if ($current === '' || $current === $normalized) {
                        return;
                    }

                    DB::table($table)
                        ->where($column, $current)
                        ->update([$column => $normalized]);
                });
        }
    }

    public function down(): void
    {
        // Irreversible: original casing is not retained.
    }

    private function resolveUserEmailConflicts(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $groups = DB::table('users')
            ->selectRaw('LOWER(email) as normalized_email, COUNT(*) as total, MIN(id) as keep_id')
            ->groupByRaw('LOWER(email)')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            DB::table('users')
                ->whereRaw('LOWER(email) = ?', [$group->normalized_email])
                ->where('id', '!=', $group->keep_id)
                ->orderBy('id')
                ->get(['id', 'email'])
                ->each(function (object $row): void {
                    DB::table('users')
                        ->where('id', $row->id)
                        ->update([
                            'email' => 'conflict-'.$row->id.'-'.mb_strtolower(trim((string) $row->email)),
                        ]);
                });
        }
    }
};
