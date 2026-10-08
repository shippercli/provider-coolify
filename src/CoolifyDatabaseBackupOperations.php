<?php

declare(strict_types=1);

namespace ShipperCli\ProviderCoolify;

trait CoolifyDatabaseBackupOperations
{
    /** Import a backup into an explicitly identified database component in a service.
     *
     * @param array<string, mixed> $import Coolify source-specific import options.
     * @return array<string, mixed>
     */
    public function importServiceDatabaseBackup(string $serviceUuid, string $databaseUuid, array $import): array
    {
        if (\trim($serviceUuid) === '' || \trim($databaseUuid) === '') {
            throw new \InvalidArgumentException('Coolify service and database UUIDs are required to import a service database backup.');
        }

        return $this->getClient()->request(
            'POST',
            '/services/'.\rawurlencode($serviceUuid).'/databases/'.\rawurlencode($databaseUuid).'/imports',
            $import,
        );
    }

    /** Delete one explicitly identified scheduled backup configuration.
     *
     * Set $deleteS3 to true only when its S3 backup files should also be deleted.
     *
     * @return array<string, mixed>
     */
    public function deleteDatabaseBackup(string $databaseUuid, string $backupUuid, bool $deleteS3 = false): array
    {
        if (\trim($databaseUuid) === '' || \trim($backupUuid) === '') {
            throw new \InvalidArgumentException('Coolify database and backup UUIDs are required to delete a database backup configuration.');
        }

        $path = '/databases/'.\rawurlencode($databaseUuid).'/backups/'.\rawurlencode($backupUuid);
        if ($deleteS3) {
            $path .= '?'.\http_build_query(['delete_s3' => true]);
        }

        return $this->getClient()->request('DELETE', $path);
    }

    /** Delete one explicitly identified backup execution.
     *
     * Set $deleteS3 to true only when its S3 backup file should also be deleted.
     *
     * @return array<string, mixed>
     */
    public function deleteDatabaseBackupExecution(
        string $databaseUuid,
        string $backupUuid,
        string $executionUuid,
        bool $deleteS3 = false,
    ): array {
        if (\trim($databaseUuid) === '' || \trim($backupUuid) === '' || \trim($executionUuid) === '') {
            throw new \InvalidArgumentException('Coolify database, backup, and execution UUIDs are required to delete a database backup execution.');
        }

        $path = '/databases/'.\rawurlencode($databaseUuid)
            .'/backups/'.\rawurlencode($backupUuid)
            .'/executions/'.\rawurlencode($executionUuid);
        if ($deleteS3) {
            $path .= '?'.\http_build_query(['delete_s3' => true]);
        }

        return $this->getClient()->request('DELETE', $path);
    }
}
