<?php
/**
 * TX Hash Idempotency Handler
 *
 * Prevents reuse of transaction hashes across multiple orders.
 * Critical security feature to prevent replay attacks.
 *
 * @package LayerCryptoCheckout
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * LCCP TX Hash Manager Class
 */
class LCCP_TxHash {

    /**
     * Table name (without prefix)
     */
    const TABLE_NAME = 'lccp_used_txhash';

    /**
     * Get full table name with WordPress prefix
     *
     * @return string
     */
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Create the database table on plugin activation
     */
    public static function create_table() {
        global $wpdb;

        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tx_hash VARCHAR(66) NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            network VARCHAR(50) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY tx_hash_unique (tx_hash)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Store the database version for future upgrades
        update_option('lccp_txhash_db_version', '1.0');
    }

    /**
     * Check if a transaction hash has already been used
     *
     * @param string $tx_hash Transaction hash to check
     * @return array|false Returns array with order_id if found, false otherwise
     */
    public static function is_used($tx_hash) {
        global $wpdb;

        $tx_hash = strtolower(sanitize_text_field($tx_hash));

        // Validate format
        if (!preg_match('/^0x[a-f0-9]{64}$/', $tx_hash)) {
            return false;
        }

        $table_name = self::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT order_id, network, created_at FROM {$table_name} WHERE tx_hash = %s",
                $tx_hash
            ),
            ARRAY_A
        );

        return $result ? $result : false;
    }

    /**
     * Record a transaction hash as used
     *
     * Uses INSERT IGNORE to handle race conditions - if two requests try to
     * insert the same tx_hash simultaneously, only one will succeed.
     *
     * @param string $tx_hash Transaction hash
     * @param int $order_id WooCommerce order ID
     * @param string $network Network key (e.g., 'base_sepolia')
     * @return bool|string True on success, 'duplicate' if already exists, false on error
     */
    public static function record($tx_hash, $order_id, $network) {
        global $wpdb;

        $tx_hash = strtolower(sanitize_text_field($tx_hash));
        $order_id = absint($order_id);
        $network = sanitize_text_field($network);

        // Validate format
        if (!preg_match('/^0x[a-f0-9]{64}$/', $tx_hash)) {
            return false;
        }

        if ($order_id <= 0) {
            return false;
        }

        $table_name = self::get_table_name();

        // Use INSERT IGNORE to handle race conditions gracefully
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$table_name} (tx_hash, order_id, network) VALUES (%s, %d, %s)",
                $tx_hash,
                $order_id,
                $network
            )
        );

        if ($result === false) {
            return false;
        }

        // If affected rows is 0, the tx_hash already existed (IGNORE kicked in)
        if ($wpdb->rows_affected === 0) {
            return 'duplicate';
        }

        return true;
    }

    /**
     * Get the order ID associated with a transaction hash
     *
     * @param string $tx_hash Transaction hash
     * @return int|false Order ID or false if not found
     */
    public static function get_order_id($tx_hash) {
        $result = self::is_used($tx_hash);
        return $result ? absint($result['order_id']) : false;
    }

    /**
     * Delete a transaction hash record (for testing/cleanup purposes)
     *
     * @param string $tx_hash Transaction hash
     * @return bool
     */
    public static function delete($tx_hash) {
        global $wpdb;

        $tx_hash = strtolower(sanitize_text_field($tx_hash));
        $table_name = self::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete(
            $table_name,
            array('tx_hash' => $tx_hash),
            array('%s')
        );

        return $result !== false;
    }

    /**
     * Cleanup old records (optional maintenance)
     *
     * @param int $days Number of days to keep records
     * @return int Number of deleted records
     */
    public static function cleanup($days = 365) {
        global $wpdb;

        $table_name = self::get_table_name();
        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE created_at < %s",
                $cutoff
            )
        );

        return $deleted !== false ? $deleted : 0;
    }
}
