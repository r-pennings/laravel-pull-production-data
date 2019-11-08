<?php

namespace DigiFactory\PullProductionData;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class PullProductionDataCommand extends Command
{
    protected $signature = 'pull-production-data {--D|no-database : Whether the database should not be synced} {--S|no-storage-folder : Whether the storage folder should not be synced}';
    protected $description = 'Pull your production storage folder and database to your local environment';

    protected $user;
    protected $host;
    protected $port;
    protected $path;

    protected $productionDatabaseName;
    protected $productionDatabaseUser;
    protected $productionDatabasePassword;

    public function __construct()
    {
        parent::__construct();

        $deployServer = config('pull-production-data.deploy_server');
        $this->path = config('pull-production-data.deploy_path');

        preg_match('/(.*)@([^\s]+)(?:\s-p)?([0-9]*)/', $deployServer, $matches);

        if (count($matches) === 4) {
            $this->user = $matches[1];
            $this->host = $matches[2];
            $this->port = $matches[3] ? (int)$matches[3] : 22;
        }
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if (!$this->user || !$this->host || !$this->path) {
            $this->error('Make sure DEPLOY_SERVER and DEPLOY_PATH are set in your .env file!');

            return;
        }

        if (!$this->confirm("Is it alright to sync production data from {$this->user} on {$this->host}?", false)) {
            $this->error('Aborted!');

            return;
        }

        if (! $this->option('no-database')) {
            $this->syncDatabase();
        } else {
            $this->line('Skipping database...');
        }

        if (! $this->option('no-storage-folder')) {
            $this->syncStorageFolder();
        } else {
            $this->line('Skipping storage folder...');
        }
    }

    public function syncDatabase()
    {
        $this->fetchProductionDatabaseCredentials();
        $this->fetchProductionDatabaseBackup();
        $this->importDatabaseBackup();
    }

    public function syncStorageFolder()
    {
        $this->info('Removing current storage folder...');

        File::deleteDirectory(storage_path().'/app');

        $this->info('Storage folder removed!');

        $source = sprintf('%s@%s:%s', $this->user, $this->host, $this->path.'/storage/app');
        $destination = storage_path();

        $process = new Process(['scp', '-r', '-P'.$this->port, $source, $destination]);
        $process->setTimeout(config('pull-production-data.timeout'));

        $this->info(sprintf('Syncing data from [%s] to [%s]...', $source, $destination));

        $process->run();

        $this->info('Data synced!');
    }

    private function fetchProductionDatabaseCredentials()
    {
        $this->info('Fetching production database credentials...');

        $process = new Process(['ssh', "{$this->user}@{$this->host}", "-p{$this->port}", "cat public_html/.env"]);
        $process->run();

        $env = $process->getOutput();

        preg_match('/(?:DB_DATABASE=)(.*)/', $env, $matches);

        $this->productionDatabaseName = $matches[1];

        preg_match('/(?:DB_USERNAME=)(.*)/', $env, $matches);

        $this->productionDatabaseUser = $matches[1];

        preg_match('/(?:DB_PASSWORD=)(.*)/', $env, $matches);

        $this->productionDatabasePassword = $matches[1];

        $this->info('Credentials fetched...');
    }

    private function fetchProductionDatabaseBackup()
    {
        // Create backup
        $this->info('Creating production database backup...');

        $command = sprintf('mysqldump -u%s -p%s %s > %s/database.sql', $this->productionDatabaseUser, $this->productionDatabasePassword, $this->productionDatabaseName, $this->path);

        $process = new Process(['ssh', "{$this->user}@{$this->host}", "-p{$this->port}", $command]);
        $process->run();

        $this->info('Backup created!');

        // Download backup
        $this->info('Downloading production database backup...');

        $source = sprintf('%s@%s:%s', $this->user, $this->host, $this->path.'/database.sql');
        $destination = base_path().'/database.sql';

        $process = new Process(['scp', '-P'.$this->port, $source, $destination]);
        $process->run();

        $this->info('Backup downloaded!');

        // Remove backup from production machine
        $this->info('Remove database backup from production machine...');

        $command = sprintf('rm %s/database.sql', $this->path);

        $process = new Process(['ssh', "{$this->user}@{$this->host}", "-p{$this->port}", $command]);
        $process->run();

        $this->info('Database removed!');
    }

    private function importDatabaseBackup()
    {
        // Remove all tables
        $this->info('Remove all local tables...');

        DB::getSchemaBuilder()->dropAllTables();

        $this->info('Tables removed!');

        // Import database backup
        $this->info('Importing database backup...');

        DB::unprepared(file_get_contents(base_path().'/database.sql'));

        $this->info('Database import ready!');

        // Remove database backup
        $this->info('Deleting database backup...');

        unlink(base_path().'/database.sql');

        $this->info('Database backup deleted!');
    }

    public function info($string, $verbosity = null)
    {
        parent::info('['.date('Y-m-d H:i:s').'] '.$string, $verbosity);
    }
}
