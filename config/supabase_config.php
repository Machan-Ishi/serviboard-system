<?php
/**
 * Supabase Integration Configuration
 *
 * This file contains settings and utilities for Supabase integration.
 * It provides centralized control over Supabase features and debugging.
 */

require_once __DIR__ . '/../config/config.php';

/**
 * Supabase Integration Settings
 */
class SupabaseConfig {
    // Integration toggles
    const MIRRORING_ENABLED = true;
    const AUDIT_LOGGING_ENABLED = true;
    const REAL_TIME_SYNC_ENABLED = false; // For future real-time features

    // Performance settings
    const MIRROR_TIMEOUT = 5; // seconds
    const MAX_RETRY_ATTEMPTS = 3;
    const BATCH_SIZE = 10; // For future batch operations

    // Debug settings
    const DEBUG_MODE = false;
    const LOG_ERRORS = true;
    const LOG_SUCCESSFUL_OPERATIONS = false;

    /**
     * Check if Supabase integration is fully enabled
     */
    public static function isEnabled(): bool {
        if (!function_exists('curl_init')) {
            return false; // Cannot work without cURL
        }

        return self::MIRRORING_ENABLED &&
               supabase_is_configured(); // Removed connection test for now
    }

    /**
     * Get integration status information
     */
    public static function getStatus(): array {
        $curlAvailable = function_exists('curl_init');
        $mode = function_exists('supabase_mode') ? supabase_mode() : 'disabled';
        $connectionDetails = $curlAvailable && function_exists('supabase_test_connection_details')
            ? supabase_test_connection_details()
            : ['ok' => false, 'error' => $curlAvailable ? 'Connection details unavailable' : 'cURL extension is missing'];

        return [
            'enabled' => self::isEnabled(),
            'configured' => supabase_is_configured(),
            'connected' => $curlAvailable ? (bool) ($connectionDetails['ok'] ?? false) : false,
            'connection_status' => $connectionDetails['status'] ?? 0,
            'connection_endpoint' => $connectionDetails['endpoint'] ?? null,
            'connection_error' => $connectionDetails['error'] ?? null,
            'mode' => $mode,
            'mode_label' => function_exists('supabase_mode_label') ? supabase_mode_label() : 'Supabase status unavailable',
            'mirroring_enabled' => self::MIRRORING_ENABLED,
            'audit_enabled' => self::AUDIT_LOGGING_ENABLED,
            'real_time_enabled' => self::REAL_TIME_SYNC_ENABLED,
            'debug_mode' => self::DEBUG_MODE,
            'curl_available' => $curlAvailable,
            'last_test' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Safe mirroring wrapper with error handling
     */
    public static function safeMirror(string $table, array $data, string $action = 'INSERT', array $where = []): bool {
        if (!self::isEnabled()) {
            if (self::DEBUG_MODE) {
                error_log("Supabase mirroring skipped: integration not enabled");
            }
            return false;
        }

        try {
            $startTime = microtime(true);
            $result = supabase_mirror($table, $data, $action, $where);
            $duration = microtime(true) - $startTime;

            if (self::LOG_SUCCESSFUL_OPERATIONS || !$result) {
                $message = sprintf(
                    "Supabase mirror: %s %s (%s) - %.3fs",
                    $action,
                    $table,
                    $result ? 'SUCCESS' : 'FAILED',
                    $duration
                );
                error_log($message);
            }

            return $result;
        } catch (Throwable $e) {
            if (self::LOG_ERRORS) {
                error_log("Supabase mirror exception: " . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Batch mirror multiple records
     */
    public static function batchMirror(array $operations): array {
        if (!self::isEnabled()) {
            return array_fill(0, count($operations), false);
        }

        $results = [];
        foreach ($operations as $operation) {
            $results[] = self::safeMirror(
                $operation['table'],
                $operation['data'],
                $operation['action'] ?? 'INSERT',
                $operation['where'] ?? []
            );
        }

        return $results;
    }

    /**
     * Health check for Supabase integration
     */
    public static function healthCheck(): array {
        $status = self::getStatus();

        $issues = [];
        if (!$status['configured']) {
            $issues[] = 'Supabase credentials not configured';
        }
        if (!$status['connected']) {
            $issues[] = 'Cannot connect to Supabase'
                . (!empty($status['connection_error']) ? ': ' . $status['connection_error'] : '');
        }
        if (!$status['mirroring_enabled']) {
            $issues[] = 'Data mirroring is disabled';
        }
        if ($status['mode'] === 'disabled') {
            $issues[] = 'Primary database and mirroring are both using local-only configuration';
        }

        return [
            'healthy' => empty($issues),
            'status' => $status,
            'issues' => $issues,
            'timestamp' => date('c')
        ];
    }
}

/**
 * Helper functions for easy integration
 */

if (!function_exists('supabase_enabled')) {
    function supabase_enabled(): bool {
        return SupabaseConfig::isEnabled();
    }
}

if (!function_exists('supabase_mirror_safe')) {
    function supabase_mirror_safe(string $table, array $data, string $action = 'INSERT', array $where = []): bool {
        return SupabaseConfig::safeMirror($table, $data, $action, $where);
    }
}

if (!function_exists('supabase_health_check')) {
    function supabase_health_check(): array {
        return SupabaseConfig::healthCheck();
    }
}
?>
