<?php

namespace App\ModuloCore\Service;

use App\ModuloCore\Entity\Modulo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Psr\Log\LoggerInterface;

class BackupService
{
    private $backupDir;
    private $projectDir;
    private $filesystem;
    private $logger;
    private $databasePath;

    public function __construct(
        string $backupDir,
        string $projectDir,
        Filesystem $filesystem,
        LoggerInterface $logger
    ) {
        $this->backupDir = $backupDir;
        $this->projectDir = $projectDir;
        $this->filesystem = $filesystem;
        $this->logger = $logger;
        $this->databasePath = $this->projectDir . '/var/data/database.sqlite';
    }

    public function createBackup(string $name, ?string $description): string
    {
        if (!$this->filesystem->exists($this->backupDir)) {
            $this->filesystem->mkdir($this->backupDir);
        }

        $timestamp = (new \DateTime())->format('Ymd_Hi');
        $shortHash = substr(md5($name), 0, 8);
        $filename = sprintf('bkp_%s_%s.zip', $timestamp, $shortHash);
        $backupPath = $this->backupDir . '/' . $filename;

        $tempDir = $this->projectDir . '/var/tmp/backup_' . $timestamp;
        $this->filesystem->mkdir($tempDir);

        try {
            $this->backupDatabase($tempDir);

            $metaData = [
                'type' => 'database',
                'name' => $name,
                'description' => $description,
                'created_at' => (new \DateTime())->format('c'),
            ];
            $this->filesystem->dumpFile($tempDir . '/meta_' . $timestamp . '.json', json_encode($metaData));

            $this->createZip($tempDir, $backupPath);

            $this->logger->info('Backup de base de datos creado: ' . $filename);
            return $filename;
        } catch (\Exception $e) {
            $this->logger->error('Error al crear backup: ' . $e->getMessage());
            throw $e;
        } finally {
            $this->filesystem->remove($tempDir);
        }
    }


    private function createZip(string $sourceDir, string $zipPath): void
    {
        $process = new Process(['zip', '-r', $zipPath, '.', '-i', '*'], $sourceDir);
        $process->setTimeout(1800);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        if (!$this->filesystem->exists($zipPath)) {
            throw new \Exception('Error al crear el archivo ZIP del backup.');
        }
    }

    private function backupDatabase(string $tempDir): void
    {
        if (!$this->filesystem->exists($this->databasePath)) {
            throw new \Exception('Archivo de base de datos SQLite no encontrado: ' . $this->databasePath);
        }

        $dumpFile = $tempDir . '/database.sqlite';
        $this->filesystem->copy($this->databasePath, $dumpFile);

        if (!$this->filesystem->exists($dumpFile)) {
            throw new \Exception('Error al copiar el archivo de la base de datos SQLite.');
        }
    }

    public function createFullBackup(string $name, ?string $description = null): array
    {
        $this->logger->info('Iniciando backup completo: ' . $name);
        
        $timestamp = date('YmdHis');
        $filename = "backup_full_{$timestamp}.zip";
        $backupPath = $this->backupDir . '/' . $filename;
        
        $metaInfo = [
            'name' => $name,
            'type' => 'full',
            'description' => $description,
            'created_at' => date('Y-m-d H:i:s'),
            'modules' => $this->getActiveModulesNames()
        ];
        
        $metaFile = $this->backupDir . '/meta_' . $timestamp . '.json';
        file_put_contents($metaFile, json_encode($metaInfo, JSON_PRETTY_PRINT));
        
        $dbFile = $this->backupDatabase($timestamp);
        
        $dirsToBackup = [
            'config',
            'src/ModuloCore',
            'templates',
            'public',
            $metaFile,
            $dbFile
        ];
        
        $activeModules = $this->getAvailableModules(true);
        foreach ($activeModules as $module) {
            $moduleName = $module->getNombre();
            $moduleDir = 'src/Modulo' . $moduleName;
            if ($this->filesystem->exists($this->projectDir . '/' . $moduleDir) && $moduleName !== 'Core') {
                $dirsToBackup[] = $moduleDir;
            }
        }
        
        try {
            $process = new Process(array_merge(
                ['zip', '-r', $backupPath],
                $dirsToBackup,
                ['-x', '*.git*', '*.env*', '*.cache*', '*.log*']
            ));
            $process->setWorkingDirectory($this->projectDir);
            $process->setTimeout(3600);
            $process->run();
            
            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }
            
            $this->saveBackupRecord($metaInfo, $filename, filesize($backupPath));
            
            $this->filesystem->remove([$metaFile, $dbFile]);
            
            $this->logger->info('Backup completo creado: ' . $filename);
            
            return [
                'success' => true,
                'filename' => $filename,
                'path' => $backupPath,
                'size' => $this->formatBytes(filesize($backupPath)),
                'meta' => $metaInfo
            ];
        } catch (\Exception $e) {
            $this->logger->error('Error al crear backup completo: ' . $e->getMessage());
            if (file_exists($metaFile)) $this->filesystem->remove($metaFile);
            if (file_exists($dbFile)) $this->filesystem->remove($dbFile);
            if (file_exists($backupPath)) $this->filesystem->remove($backupPath);
            
            throw $e;
        }
    }

    public function createDatabaseBackup(string $name, ?string $description = null): array
    {
        $this->logger->info('Iniciando backup de base de datos: ' . $name);
        
        $timestamp = date('YmdHis');
        $dbFile = $this->backupDatabase($timestamp);
        
        $metaInfo = [
            'name' => $name,
            'type' => 'database',
            'description' => $description,
            'created_at' => date('Y-m-d H:i:s'),
            'modules' => ['database']
        ];
        
        $filename = "backup_db_{$timestamp}.zip";
        $backupPath = $this->backupDir . '/' . $filename;
        
        $metaFile = $this->backupDir . '/meta_' . $timestamp . '.json';
        file_put_contents($metaFile, json_encode($metaInfo, JSON_PRETTY_PRINT));
        
        try {
            $process = new Process(['zip', '-j', $backupPath, $dbFile, $metaFile]);
            $process->setWorkingDirectory($this->projectDir);
            $process->run();
            
            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }
            
            $this->saveBackupRecord($metaInfo, $filename, filesize($backupPath));
            
            $this->filesystem->remove([$metaFile, $dbFile]);
            
            $this->logger->info('Backup de base de datos creado: ' . $filename);
            
            return [
                'success' => true,
                'filename' => $filename,
                'path' => $backupPath,
                'size' => $this->formatBytes(filesize($backupPath)),
                'meta' => $metaInfo
            ];
        } catch (\Exception $e) {
            $this->logger->error('Error al crear backup de base de datos: ' . $e->getMessage());
            if (file_exists($metaFile)) $this->filesystem->remove($metaFile);
            if (file_exists($dbFile)) $this->filesystem->remove($dbFile);
            if (file_exists($backupPath)) $this->filesystem->remove($backupPath);
            
            throw $e;
        }
    }

    public function createModulesBackup(string $name, array $moduleNames, ?string $description = null): array
    {
        $this->logger->info('Iniciando backup de módulos: ' . implode(', ', $moduleNames));
        
        $timestamp = date('YmdHis');
        $moduleSlug = implode('_', array_slice($moduleNames, 0, 3));
        if (count($moduleNames) > 3) {
            $moduleSlug .= '_and_more';
        }
        
        $filename = "backup_modules_{$moduleSlug}_{$timestamp}.zip";
        $backupPath = $this->backupDir . '/' . $filename;
        
        $metaInfo = [
            'name' => $name,
            'type' => 'modules',
            'description' => $description,
            'created_at' => date('Y-m-d H:i:s'),
            'modules' => $moduleNames
        ];
        
        $metaFile = $this->backupDir . '/meta_' . $timestamp . '.json';
        file_put_contents($metaFile, json_encode($metaInfo, JSON_PRETTY_PRINT));
        
        $moduleDirs = [];
        foreach ($moduleNames as $moduleName) {
            $moduleDir = 'src/Modulo' . $moduleName;
            if ($this->filesystem->exists($this->projectDir . '/' . $moduleDir)) {
                $moduleDirs[] = $moduleDir;
            }
        }
        
        if (empty($moduleDirs)) {
            throw new \InvalidArgumentException('No se encontraron directorios de módulos válidos para el backup');
        }
        
        try {
            $process = new Process(array_merge(
                ['zip', '-r', $backupPath],
                $moduleDirs,
                [$metaFile],
                ['-x', '*.git*', '*.env*', '*.cache*', '*.log*']
            ));
            $process->setWorkingDirectory($this->projectDir);
            $process->setTimeout(1800);
            $process->run();
            
            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }
            
            $this->saveBackupRecord($metaInfo, $filename, filesize($backupPath));
            
            $this->filesystem->remove($metaFile);
            
            $this->logger->info('Backup de módulos creado: ' . $filename);
            
            return [
                'success' => true,
                'filename' => $filename,
                'path' => $backupPath,
                'size' => $this->formatBytes(filesize($backupPath)),
                'meta' => $metaInfo
            ];
        } catch (\Exception $e) {
            $this->logger->error('Error al crear backup de módulos: ' . $e->getMessage());
            if (file_exists($metaFile)) $this->filesystem->remove($metaFile);
            if (file_exists($backupPath)) $this->filesystem->remove($backupPath);
            
            throw $e;
        }
    }

    public function restoreBackup(string $backupId): bool
    {
        $backup = $this->getBackupById($backupId);
        if (!$backup) {
            throw new \InvalidArgumentException('Backup no encontrado');
        }

        $backupPath = $this->backupDir . '/' . $backup['filename'];
        if (!file_exists($backupPath)) {
            throw new \Exception('Archivo de backup no encontrado: ' . $backup['filename']);
        }

        $this->logger->info('Iniciando restauración de backup: ' . $backup['filename']);

        $tempDir = $this->projectDir . '/var/tmp/restore_' . time();
        $this->filesystem->mkdir($tempDir);

        try {
            $process = new Process(['unzip', $backupPath, '-d', $tempDir]);
            $process->setTimeout(1800);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            $this->restoreDatabaseBackup($tempDir);

            $this->logger->info('Backup de base de datos restaurado correctamente: ' . $backup['filename']);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error al restaurar backup: ' . $e->getMessage());
            throw $e;
        } finally {
            $this->filesystem->remove($tempDir);
        }
    }

    private function restoreFullBackup(string $tempDir): bool
    {
        $this->restoreDatabaseFromBackup($tempDir);
        
        $mainDirs = ['config', 'src', 'templates', 'public'];
        foreach ($mainDirs as $dir) {
            if ($this->filesystem->exists($tempDir . '/' . $dir)) {
                $backupOriginal = $this->projectDir . '/var/tmp/' . $dir . '_original_' . time();
                if ($this->filesystem->exists($this->projectDir . '/' . $dir)) {
                    $this->filesystem->mirror($this->projectDir . '/' . $dir, $backupOriginal);
                }
                
                $this->filesystem->mirror($tempDir . '/' . $dir, $this->projectDir . '/' . $dir, null, ['override' => true]);
            }
        }
        
        $this->clearCache();
        
        $this->logger->info('Backup completo restaurado correctamente');
        return true;
    }

    private function restoreDatabaseBackup(string $tempDir): bool
    {
        $dbFile = $tempDir . '/database.sqlite';
        if (!$this->filesystem->exists($dbFile)) {
            throw new \Exception('Archivo de base de datos SQLite no encontrado en el backup.');
        }

        if ($this->filesystem->exists($this->databasePath)) {
            $this->filesystem->copy($this->databasePath, $this->databasePath . '.bak');
        }

        $this->filesystem->copy($dbFile, $this->databasePath, true);

        $this->logger->info('Base de datos SQLite restaurada correctamente');
        return true;
    }

    private function restoreModulesBackup(string $tempDir, array $moduleNames): bool
    {
        foreach ($moduleNames as $moduleName) {
            $moduleDir = 'src/Modulo' . $moduleName;
            $moduleTempPath = $tempDir . '/' . $moduleDir;
            $moduleTargetPath = $this->projectDir . '/' . $moduleDir;
            
            if ($this->filesystem->exists($moduleTempPath)) {
                if ($this->filesystem->exists($moduleTargetPath)) {
                    $backupOriginal = $this->projectDir . '/var/tmp/' . $moduleDir . '_original_' . time();
                    $this->filesystem->mirror($moduleTargetPath, $backupOriginal);
                }
                
                $this->filesystem->mirror($moduleTempPath, $moduleTargetPath, null, ['override' => true]);
                $this->logger->info('Módulo restaurado: ' . $moduleName);
            }
        }
        
        $this->clearCache();
        
        $this->logger->info('Backup de módulos restaurado correctamente');
        return true;
    }

    private function restoreDatabaseFromBackup(string $tempDir): void
    {
        $sqlFiles = array_merge(
            glob($tempDir . '/*.sql'),
            glob($tempDir . '/*.sql.gz'),
            glob($tempDir . '/*.db')
        );
        
        if (empty($sqlFiles)) {
            $this->logger->warning('No se encontraron archivos de base de datos en el backup.');
            return;
        }
        
        $dbFile = $sqlFiles[0];
        $dbParams = $this->getDatabaseParams();
        
        $currentDbBackup = $this->backupDatabase('before_restore_' . time(), false);
        
        try {
            if (strpos($dbFile, '.db') !== false) {
                $dbPath = $this->projectDir . '/var/data/database.sqlite';
                if ($this->filesystem->exists($dbPath)) {
                    $this->filesystem->copy($dbPath, $dbPath . '.bak');
                }
                $this->filesystem->copy($dbFile, $dbPath);
            } else {
                throw new \Exception('Tipo de base de datos no soportado: ' . $dbParams['driver']);
            }
            
            $this->logger->info('Base de datos restaurada correctamente');
        } catch (\Exception $e) {
            $this->logger->error('Error al restaurar la base de datos: ' . $e->getMessage());
            throw $e;
        }
    }

    public function deleteBackup(string $backupId): bool
    {
        $backup = $this->getBackupById($backupId);
        if (!$backup) {
            throw new \InvalidArgumentException('Backup no encontrado');
        }

        $backupPath = $this->backupDir . '/' . $backup['filename'];
        if ($this->filesystem->exists($backupPath)) {
            try {
                $this->filesystem->remove($backupPath);
                $this->logger->info('Archivo de backup eliminado: ' . $backup['filename']);
            } catch (\Exception $e) {
                $this->logger->error('Error al eliminar el archivo de backup: ' . $e->getMessage());
                throw new \Exception('No se pudo eliminar el archivo de backup: ' . $e->getMessage());
            }
        } else {
            $this->logger->warning('Archivo de backup no encontrado: ' . $backup['filename']);
        }

        $this->logger->info('Backup eliminado: ' . $backup['filename']);
        return true;
    }

    public function getBackupById(string $id): ?array
    {
        $backups = $this->getBackupsList();
        foreach ($backups as $backup) {
            if ($backup['id'] === $id) {
                return $backup;
            }
        }

        return null;
    }

    public function getBackupStats(): array
    {
        $backups = $this->getBackupsList();
        $totalSize = array_sum(array_column($backups, 'size'));
        $diskFreeSpace = disk_free_space($this->backupDir);
        $latestBackup = !empty($backups) ? $backups[0] : null;

        return [
            'totalCount' => count($backups),
            'totalSize' => $totalSize,
            'formattedTotalSize' => $this->formatBytes($totalSize),
            'diskFreeSpace' => $diskFreeSpace,
            'formattedDiskFreeSpace' => $this->formatBytes($diskFreeSpace),
            'latestBackup' => $latestBackup,
        ];
    }

    public function getBackupFile(string $id): array
    {
        $backup = $this->getBackupById($id);
        if (!$backup) {
            throw new \InvalidArgumentException('Backup no encontrado');
        }

        $backupPath = $this->backupDir . '/' . $backup['filename'];
        if (!$this->filesystem->exists($backupPath)) {
            throw new \Exception('El archivo del backup no existe: ' . $backup['filename']);
        }

        return [
            'path' => $backupPath,
            'filename' => $backup['filename'],
            'size' => $backup['size']
        ];
    }

    public function getBackupsList(): array
    {
        $backups = [];
        $files = glob($this->backupDir . '/*.zip');

        foreach ($files as $file) {
            $filename = basename($file);
            $metaFile = $this->backupDir . '/meta_' . explode('_', $filename)[2] . '.json';

            $metaInfo = [];
            if ($this->filesystem->exists($metaFile)) {
                $metaInfo = json_decode(file_get_contents($metaFile), true);
            }

            $createdAt = \DateTime::createFromFormat('Ymd_His', explode('_', $filename)[2]);
            $size = filesize($file);

            $backups[] = [
                'id' => md5($filename),
                'filename' => $filename,
                'name' => $metaInfo['name'] ?? $filename,
                'description' => $metaInfo['description'] ?? '',
                'type' => 'database',
                'createdAt' => $createdAt ?: new \DateTime(),
                'size' => $size,
                'formattedSize' => $this->formatBytes($size),
            ];
        }

        usort($backups, function ($a, $b) {
            return $b['createdAt'] <=> $a['createdAt'];
        });

        return $backups;
    }

    public function getAvailableModules(bool $onlyActive = false): array
    {
        $moduloRepository = $this->entityManager->getRepository(Modulo::class);
        
        if ($onlyActive) {
            return $moduloRepository->findBy(['estado' => true]);
        }
        
        return $moduloRepository->findAll();
    }

    private function getActiveModulesNames(): array
    {
        $modules = $this->getAvailableModules(true);
        return array_map(function($module) {
            return $module->getNombre();
        }, $modules);
    }

    private function clearCache(): void
    {
        $process = new Process(['php', 'bin/console', 'cache:clear']);
        $process->setWorkingDirectory($this->projectDir);
        $process->run();
        
        if (!$process->isSuccessful()) {
            $this->logger->warning('Error al limpiar la caché: ' . $process->getErrorOutput());
        }
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    private function getDatabaseParams(): array
    {
        $conn = $this->entityManager->getConnection();
        return $conn->getParams();
    }

    private function saveBackupRecord(array $metaInfo, string $filename, int $size): void
    {
    }

    private function deleteBackupRecord(string $id): void
    {
    }
}