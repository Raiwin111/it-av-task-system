<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require __DIR__ . "/../config/db.local.php";

$project_root = realpath(__DIR__ . "/..");
$backup_directory = $project_root . DIRECTORY_SEPARATOR . "backups";
if (!is_dir($backup_directory) && !mkdir($backup_directory, 0700, true) && !is_dir($backup_directory)) {
    fwrite(STDERR, "Unable to create the backup directory." . PHP_EOL);
    exit(1);
}

$label = $argv[1] ?? date("Ymd-His");
if (!preg_match('/^[A-Za-z0-9_-]+$/', $label)) {
    fwrite(STDERR, "Backup label contains unsupported characters." . PHP_EOL);
    exit(1);
}

$backup_path = $backup_directory . DIRECTORY_SEPARATOR . $db_name . "-" . $label . ".sql";
$command = [
    "C:\\xampp\\mysql\\bin\\mysqldump.exe",
    "--host=" . $db_host,
    "--user=" . $db_user,
    "--default-character-set=utf8mb4",
    "--single-transaction",
    "--routines",
    "--triggers",
    "--result-file=" . $backup_path,
    $db_name,
];

$environment = getenv();
$environment["MYSQL_PWD"] = $db_pass;
$process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, null, $environment);
if (!is_resource($process)) {
    fwrite(STDERR, "Unable to start mysqldump." . PHP_EOL);
    exit(1);
}

$exit_code = proc_close($process);
if ($exit_code !== 0 || !is_file($backup_path) || filesize($backup_path) === 0) {
    fwrite(STDERR, "Database backup failed." . PHP_EOL);
    exit(1);
}

echo $backup_path, PHP_EOL;
