<?php
/**
 * Plugin SolwedES - Custom Logger
 *
 * Writes logs to a dedicated file: MyFiles/Logs/solwed.log
 * Makes it easy to debug SolwedES plugin operations.
 *
 * Usage:
 *   SolwedLogger::info('Something happened');
 *   SolwedLogger::error('Something failed', ['context' => 'data']);
 *   SolwedLogger::debug('Detailed info', $someObject);
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Lib;

class SolwedLogger
{
    private const LOG_FILE = 'solwed.log';
    private const MAX_SIZE = 5 * 1024 * 1024; // 5MB before rotation

    /**
     * Log an info message
     */
    public static function info(string $message, mixed $context = null): void
    {
        self::log('INFO', $message, $context);
    }

    /**
     * Log an error message
     */
    public static function error(string $message, mixed $context = null): void
    {
        self::log('ERROR', $message, $context);
    }

    /**
     * Log a warning message
     */
    public static function warning(string $message, mixed $context = null): void
    {
        self::log('WARN', $message, $context);
    }

    /**
     * Log Stripe webhook events
     */
    public static function stripe(string $message, mixed $context = null): void
    {
        self::log('STRIPE', $message, $context);
    }

    /**
     * Core logging method
     */
    private static function log(string $level, string $message, mixed $context = null): void
    {
        $logPath = self::getLogPath();
        if (!$logPath) {
            return;
        }

        // Rotate if needed
        self::rotateIfNeeded($logPath);

        // Build log entry
        $timestamp = date('Y-m-d H:i:s');
        $entry = sprintf("[%s] [%s] %s", $timestamp, $level, $message);

        // Add context if provided
        if ($context !== null) {
            if (is_array($context) || is_object($context)) {
                $entry .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $entry .= ' | ' . (string)$context;
            }
        }

        $entry .= PHP_EOL;

        // Write to file
        file_put_contents($logPath, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Get the log file path
     */
    private static function getLogPath(): ?string
    {
        // FacturaScripts logs directory
        $logsDir = FS_FOLDER . '/MyFiles/Logs';

        // Create directory if it doesn't exist
        if (!is_dir($logsDir)) {
            if (!mkdir($logsDir, 0775, true)) {
                return null;
            }
        }

        return $logsDir . '/' . self::LOG_FILE;
    }

    /**
     * Rotate log file if it exceeds max size
     */
    private static function rotateIfNeeded(string $logPath): void
    {
        if (!file_exists($logPath)) {
            return;
        }

        if (filesize($logPath) > self::MAX_SIZE) {
            $backupPath = $logPath . '.' . date('Y-m-d_His') . '.bak';
            rename($logPath, $backupPath);

            // Keep only last 5 backups
            $dir = dirname($logPath);
            $backups = glob($dir . '/solwed.log.*.bak');
            if (count($backups) > 5) {
                usort($backups, fn($a, $b) => filemtime($a) - filemtime($b));
                $toDelete = array_slice($backups, 0, count($backups) - 5);
                foreach ($toDelete as $file) {
                    unlink($file);
                }
            }
        }
    }

}
