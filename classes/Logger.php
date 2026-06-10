<?php

/**
 * @file classes/Logger.php
 *
 * Copyright (c) 2025 Hendrix Nwaokolo, Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class Logger
 *
 * @brief Paystack plugin logging system with configurable levels
 */

namespace APP\plugins\paymethod\paystack\classes;

use Exception;

class Logger
{
    // Log levels (increasing severity)
    const LEVEL_DEBUG = 0;
    const LEVEL_INFO = 1;
    const LEVEL_WARNING = 2;
    const LEVEL_ERROR = 3;
    const LEVEL_CRITICAL = 4;

    /**
     * Log level names
     */
    protected static $levelNames = [
        self::LEVEL_DEBUG => 'DEBUG',
        self::LEVEL_INFO => 'INFO',
        self::LEVEL_WARNING => 'WARNING',
        self::LEVEL_ERROR => 'ERROR',
        self::LEVEL_CRITICAL => 'CRITICAL',
    ];

    /**
     * Get log file path
     *
     * @param int $contextId
     * @return string
     */
    protected static function getLogFile($contextId)
    {
        $logDir = \PKP\config\Config::getVar('files', 'files_dir') . '/paystack_logs';
        if (!file_exists($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        return $logDir . '/paystack_' . $contextId . '_' . date('Y-m') . '.log';
    }

    /**
     * Write log entry
     *
     * @param int $contextId
     * @param int $level
     * @param string $message
     * @param array $context Additional context data
     * @return bool
     */
    public static function log($contextId, $level, $message, array $context = [])
    {
        try {
            $currentLevel = self::getLogLevel($contextId);
            
            // Only log if current level allows this severity
            if ($level < $currentLevel) {
                return false;
            }

            $logFile = self::getLogFile($contextId);
            $timestamp = date('Y-m-d H:i:s');
            $levelName = self::$levelNames[$level] ?? 'UNKNOWN';
            
            $logEntry = sprintf(
                "[%s] [%s] %s",
                $timestamp,
                $levelName,
                $message
            );

            if (!empty($context)) {
                $logEntry .= ' | Context: ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            $logEntry .= PHP_EOL;

            // Write to log file (append mode)
            @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

            // Also log critical errors to PHP error log
            if ($level >= self::LEVEL_CRITICAL) {
                error_log('Paystack CRITICAL: ' . $message . ' | Context: ' . json_encode($context));
            }

            return true;
        } catch (Exception $e) {
            error_log('Paystack Logger Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Log debug message
     */
    public static function debug($contextId, $message, array $context = [])
    {
        return self::log($contextId, self::LEVEL_DEBUG, $message, $context);
    }

    /**
     * Log info message
     */
    public static function info($contextId, $message, array $context = [])
    {
        return self::log($contextId, self::LEVEL_INFO, $message, $context);
    }

    /**
     * Log warning message
     */
    public static function warning($contextId, $message, array $context = [])
    {
        return self::log($contextId, self::LEVEL_WARNING, $message, $context);
    }

    /**
     * Log error message
     */
    public static function error($contextId, $message, array $context = [])
    {
        return self::log($contextId, self::LEVEL_ERROR, $message, $context);
    }

    /**
     * Log critical message
     */
    public static function critical($contextId, $message, array $context = [])
    {
        return self::log($contextId, self::LEVEL_CRITICAL, $message, $context);
    }

    /**
     * Get current log level for context
     *
     * @param int $contextId
     * @return int
     */
    public static function getLogLevel($contextId)
    {
        // Paystack is a paymethod plugin
        $plugin = \PKP\plugins\PluginRegistry::getPlugin('paymethod', 'paystackplugin');
        if (!$plugin) {
            return self::LEVEL_WARNING; // Default level
        }
        
        $level = $plugin->getSetting($contextId, 'logLevel');
        if ($level === null) {
            return self::LEVEL_WARNING; // Default level
        }
        
        return (int) $level;
    }

    /**
     * Get available log levels
     *
     * @return array
     */
    public static function getLevels()
    {
        return [
            self::LEVEL_DEBUG => __('plugins.paymethod.paystack.settings.logLevel.debug'),
            self::LEVEL_INFO => __('plugins.paymethod.paystack.settings.logLevel.info'),
            self::LEVEL_WARNING => __('plugins.paymethod.paystack.settings.logLevel.warning'),
            self::LEVEL_ERROR => __('plugins.paymethod.paystack.settings.logLevel.error'),
            self::LEVEL_CRITICAL => __('plugins.paymethod.paystack.settings.logLevel.critical'),
        ];
    }

    /**
     * Clean old log files (older than 90 days)
     *
     * @param int $contextId
     * @return int Number of files cleaned
     */
    public static function cleanOldLogs($contextId)
    {
        $logDir = \PKP\config\Config::getVar('files', 'files_dir') . '/paystack_logs';
        if (!file_exists($logDir)) {
            return 0;
        }

        $files = glob($logDir . '/paystack_' . $contextId . '_*.log');
        $cleaned = 0;
        $cutoffTime = time() - (90 * 24 * 60 * 60); // 90 days

        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                @unlink($file);
                $cleaned++;
            }
        }

        return $cleaned;
    }
}
