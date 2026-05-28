<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Generates and optionally applies the PostgreSQL append-only role
 * for the Sovereign Infrastructure Ledger (Stage 2).
 *
 * Usage:
 *   php artisan sovereign:setup-ledger-db          -- prints SQL to stdout
 *   php artisan sovereign:setup-ledger-db --apply  -- applies it to the DB
 */
class SetupLedgerDb extends Command
{
    protected $signature = 'sovereign:setup-ledger-db
                            {--apply : Apply the SQL directly to the infra_ledger connection}
                            {--password= : Password for the ledger_writer role (required with --apply)}';

    protected $description = 'Generate (or apply) PostgreSQL append-only role for the Sovereign Infra Ledger (Stage 2)';

    public function handle(): int
    {
        $dbName = config('database.connections.infra_ledger.database', 'infra_ledger');
        $password = $this->option('password') ?? 'CHANGE_ME_'.bin2hex(random_bytes(8));

        $sql = $this->buildSql($dbName, $password);

        if ($this->option('apply')) {
            if (! $this->option('password')) {
                $this->warn('No --password given. A random password was generated — save it now!');
            }
            $this->info("Applying append-only PostgreSQL role to '{$dbName}'...");
            try {
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                    DB::connection('infra_ledger')->unprepared($statement.';');
                }
                $this->info('✔ Append-only role applied successfully.');
            } catch (\Throwable $e) {
                $this->error('Failed: '.$e->getMessage());

                return self::FAILURE;
            }
        } else {
            $this->printBanner($dbName, $password, $sql);
        }

        return self::SUCCESS;
    }

    private function buildSql(string $dbName, string $password): string
    {
        return <<<SQL
-- ══════════════════════════════════════════════════════════════
--  SOVEREIGN INFRA LEDGER — PostgreSQL Append-Only Role (Stage 2)
--  Apply this on your dedicated ledger PostgreSQL instance.
-- ══════════════════════════════════════════════════════════════

-- 1. Create the append-only writer role
CREATE ROLE ledger_writer WITH LOGIN PASSWORD '{$password}';

-- 2. Grant connect + schema usage
GRANT CONNECT ON DATABASE "{$dbName}" TO ledger_writer;
GRANT USAGE ON SCHEMA public TO ledger_writer;

-- 3. Grant INSERT + SELECT ONLY on the ledger table
--    No UPDATE. No DELETE. No TRUNCATE. Ever.
GRANT INSERT, SELECT ON TABLE infra_ledger TO ledger_writer;

-- 4. Grant sequence usage for auto-increment id
GRANT USAGE, SELECT ON SEQUENCE infra_ledger_id_seq TO ledger_writer;

-- 5. Revoke dangerous permissions from public (hardening)
REVOKE UPDATE, DELETE, TRUNCATE ON TABLE infra_ledger FROM PUBLIC;

-- 6. (Optional) Row Security — prevent even superusers from deleting via app role
ALTER TABLE infra_ledger ENABLE ROW LEVEL SECURITY;
CREATE POLICY ledger_insert_only ON infra_ledger
    AS RESTRICTIVE FOR DELETE TO PUBLIC
    USING (false);

-- ══════════════════════════════════════════════════════════════
--  To activate Stage 2, set in .env:
--    LEDGER_DB_HOST=<your-ledger-db-host>
--    LEDGER_DB_DATABASE={$dbName}
--    LEDGER_DB_USERNAME=ledger_writer
--    LEDGER_DB_PASSWORD={$password}
-- ══════════════════════════════════════════════════════════════
SQL;
    }

    private function printBanner(string $dbName, string $password, string $sql): void
    {
        $this->line('');
        $this->line('┌─────────────────────────────────────────────────────────────');
        $this->line('│  SOVEREIGN INFRA LEDGER — Stage 2: Append-Only DB Setup');
        $this->line('│  Target DB : '.$dbName);
        $this->line('│  Role      : ledger_writer');
        $this->line('│  Password  : '.$password);
        $this->line('└─────────────────────────────────────────────────────────────');
        $this->line('');
        $this->line('Run with --apply to execute against the infra_ledger connection.');
        $this->line('Or copy the SQL below and apply it manually:');
        $this->line('');
        $this->line($sql);
        $this->line('');
        $this->line('Then update your .env:');
        $this->line('  LEDGER_DB_HOST=<dedicated-ledger-host>');
        $this->line("  LEDGER_DB_DATABASE={$dbName}");
        $this->line('  LEDGER_DB_USERNAME=ledger_writer');
        $this->line("  LEDGER_DB_PASSWORD={$password}");
        $this->line('');
    }
}
