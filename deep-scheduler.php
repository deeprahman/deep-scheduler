<?php
/*
Plugin Name: Enterprise Background Processor
Plugin URI: https://example.com
Description: An industrial-grade background processing system for WordPress
Version: 1.0.0
Author: Your Name
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Enterprise_Background_Processor {
    // Singleton instance
    private static $instance = null;
    
    // DB table name for job queue
    private $table_name;
    
    // Job statuses
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    
    // Max retries for failed jobs
    const MAX_RETRIES = 5;
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'background_jobs';
        
        // Initialize the plugin
        add_action('plugins_loaded', array($this, 'init'));
        
        // Register activation hook
        register_activation_hook(__FILE__, array($this, 'activate'));
        
        // Register deactivation hook
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Register AJAX handlers
        add_action('wp_ajax_process_background_job', array($this, 'process_background_job'));
        add_action('wp_ajax_nopriv_process_background_job', array($this, 'process_background_job'));
        
        // Register WP-Cron hooks
        add_action('ebp_process_job_queue', array($this, 'process_job_queue'));
        add_action('ebp_cleanup_old_jobs', array($this, 'cleanup_old_jobs'));
    }
    
    /**
     * Initialize the plugin
     */
    public function init() {
        // Load plugin text domain
        load_plugin_textdomain('enterprise-background-processor', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Add admin menu
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
        }
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create database table
        $this->create_database_table();
        
        // Schedule cron events
        if (!wp_next_scheduled('ebp_process_job_queue')) {
            wp_schedule_event(time(), 'every_minute', 'ebp_process_job_queue');
        }
        
        if (!wp_next_scheduled('ebp_cleanup_old_jobs')) {
            wp_schedule_event(time(), 'daily', 'ebp_cleanup_old_jobs');
        }
        
        // Register custom cron interval
        add_filter('cron_schedules', function($schedules) {
            $schedules['every_minute'] = array(
                'interval' => 60,
                'display' => __('Every Minute', 'enterprise-background-processor')
            );
            return $schedules;
        });
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled hooks
        wp_clear_scheduled_hook('ebp_process_job_queue');
        wp_clear_scheduled_hook('ebp_cleanup_old_jobs');
    }
    
    /**
     * Create database table for job queue
     */
    private function create_database_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            job_name varchar(255) NOT NULL,
            job_data longtext,
            priority tinyint(2) NOT NULL DEFAULT 5,
            status varchar(20) NOT NULL DEFAULT '" . self::STATUS_PENDING . "',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            scheduled_at datetime NOT NULL,
            started_at datetime,
            completed_at datetime,
            retries int(11) NOT NULL DEFAULT 0,
            error_message text,
            lock_key varchar(64),
            lock_expiration datetime,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY priority (priority),
            KEY scheduled_at (scheduled_at),
            KEY job_name (job_name)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Add a job to the queue
     *
     * @param string $job_name The name of the job handler callback
     * @param array $job_data The data to pass to the job handler
     * @param int $delay Delay in seconds before processing
     * @param int $priority Priority (1-10, 1 is highest)
     * @return int|false Job ID on success, false on failure
     */
    public function enqueue_job($job_name, $job_data = array(), $delay = 0, $priority = 5) {
        global $wpdb;
        
        $scheduled_at = date('Y-m-d H:i:s', time() + $delay);
        
        // Sanitize and validate inputs
        if (!is_callable($job_name) && !has_action("ebp_job_{$job_name}")) {
            $this->log("Error: Job handler '{$job_name}' is not callable");
            return false;
        }
        
        $priority = max(1, min(10, intval($priority)));
        
        // Insert job into database
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'job_name'     => $job_name,
                'job_data'     => maybe_serialize($job_data),
                'priority'     => $priority,
                'status'       => self::STATUS_PENDING,
                'scheduled_at' => $scheduled_at,
            ),
            array('%s', '%s', '%d', '%s', '%s')
        );
        
        if ($result === false) {
            $this->log("Error: Failed to enqueue job '{$job_name}': " . $wpdb->last_error);
            return false;
        }
        
        $job_id = $wpdb->insert_id;
        $this->log("Success: Job '{$job_name}' (ID: {$job_id}) enqueued for processing at {$scheduled_at}");
        
        // Trigger immediate processing for high-priority jobs
        if ($priority <= 3 && $delay == 0) {
            $this->trigger_async_job_processing();
        }
        
        return $job_id;
    }
    
    /**
     * Schedule a recurring job
     *
     * @param string $job_name The name of the job handler callback
     * @param array $job_data The data to pass to the job handler
     * @param string $interval The schedule interval (hourly, twicedaily, daily)
     * @param int $priority Priority (1-10, 1 is highest)
     * @return bool Success status
     */
    public function schedule_recurring_job($job_name, $job_data = array(), $interval = 'hourly', $priority = 5) {
        // Register the recurring job
        $hook = "ebp_recurring_{$job_name}";
        
        if (!wp_next_scheduled($hook)) {
            wp_schedule_event(time(), $interval, $hook, array($job_data));
            
            // Add action to handle the recurring job
            add_action($hook, function($data) use ($job_name) {
                $this->enqueue_job($job_name, $data, 0, $priority);
            });
            
            $this->log("Success: Recurring job '{$job_name}' scheduled with interval '{$interval}'");
            return true;
        }
        
        return false;
    }
    
    /**
     * Process the job queue (called by WP-Cron)
     */
    public function process_job_queue() {
        global $wpdb;
        
        // Get current time
        $now = date('Y-m-d H:i:s');
        
        // Find jobs ready for processing
        $query = $wpdb->prepare(
            "SELECT id FROM {$this->table_name} 
            WHERE status = %s 
            AND scheduled_at <= %s 
            ORDER BY priority ASC, scheduled_at ASC 
            LIMIT 10",
            self::STATUS_PENDING,
            $now
        );
        
        $job_ids = $wpdb->get_col($query);
        
        if (empty($job_ids)) {
            return;
        }
        
        $this->log("Found " . count($job_ids) . " jobs ready for processing");
        
        // Process each job asynchronously
        foreach ($job_ids as $job_id) {
            $this->trigger_async_job_processing($job_id);
        }
    }
    
    /**
     * Process a specific background job (AJAX handler)
     */
    public function process_background_job() {
        // Verify nonce for security if coming from admin
        if (isset($_REQUEST['nonce'])) {
            check_ajax_referer('process_background_job', 'nonce');
        }
        
        // Get job ID from request
        $job_id = isset($_REQUEST['job_id']) ? intval($_REQUEST['job_id']) : 0;
        
        if (!$job_id) {
            // Process next available job if no ID specified
            $this->process_next_job();
        } else {
            // Process specific job
            $this->process_job($job_id);
        }
        
        // Return success and exit (non-blocking)
        if (defined('DOING_AJAX') && DOING_AJAX) {
            wp_send_json_success();
        } else {
            exit;
        }
    }
    
    /**
     * Process the next available job
     */
    private function process_next_job() {
        global $wpdb;
        
        // Generate a unique lock key
        $lock_key = wp_generate_password(32, false);
        $lock_expiration = date('Y-m-d H:i:s', time() + 300); // 5 minutes lock
        
        // Try to claim a job with a lock
        $query = $wpdb->prepare(
            "UPDATE {$this->table_name} 
            SET status = %s, 
                started_at = %s,
                lock_key = %s,
                lock_expiration = %s
            WHERE id IN (
                SELECT id FROM {$this->table_name}
                WHERE status = %s
                AND scheduled_at <= %s
                AND (lock_key IS NULL OR lock_expiration < %s)
                ORDER BY priority ASC, scheduled_at ASC
                LIMIT 1
            )",
            self::STATUS_PROCESSING,
            current_time('mysql'),
            $lock_key,
            $lock_expiration,
            self::STATUS_PENDING,
            current_time('mysql'),
            current_time('mysql')
        );
        
        $affected = $wpdb->query($query);
        
        if (!$affected) {
            $this->log("No jobs available for processing");
            return;
        }
        
        // Get the claimed job
        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
            WHERE status = %s AND lock_key = %s",
            self::STATUS_PROCESSING,
            $lock_key
        ));
        
        if (!$job) {
            $this->log("Failed to claim job");
            return;
        }
        
        $this->process_job($job->id);
    }
    
    /**
     * Process a specific job
     *
     * @param int $job_id The ID of the job to process
     */
    private function process_job($job_id) {
        global $wpdb;
        
        // Get job details
        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $job_id
        ));
        
        if (!$job) {
            $this->log("Error: Job ID {$job_id} not found");
            return;
        }
        
        // Skip if job is not in pending or processing status
        if ($job->status !== self::STATUS_PENDING && $job->status !== self::STATUS_PROCESSING) {
            $this->log("Skipping job ID {$job_id}: Status is {$job->status}");
            return;
        }
        
        // Mark job as processing if not already
        if ($job->status !== self::STATUS_PROCESSING) {
            $wpdb->update(
                $this->table_name,
                array(
                    'status'      => self::STATUS_PROCESSING,
                    'started_at'  => current_time('mysql'),
                    'lock_key'    => wp_generate_password(32, false),
                    'lock_expiration' => date('Y-m-d H:i:s', time() + 300)
                ),
                array('id' => $job_id),
                array('%s', '%s', '%s', '%s'),
                array('%d')
            );
        }
        
        $this->log("Processing job ID {$job_id}: {$job->job_name}");
        
        // Unserialize job data
        $job_data = maybe_unserialize($job->job_data);
        
        // Set up error handling
        try {
            $result = false;
            
            // Execute job based on handler type
            if (is_callable($job->job_name)) {
                // Direct callable
                $result = call_user_func($job->job_name, $job_data, $job_id);
            } elseif (has_action("ebp_job_{$job->job_name}")) {
                // Action hook
                do_action("ebp_job_{$job->job_name}", $job_data, $job_id);
                $result = true;
            } else {
                throw new Exception("Job handler '{$job->job_name}' is not callable");
            }
            
            // Mark job as completed
            if ($result !== false) {
                $wpdb->update(
                    $this->table_name,
                    array(
                        'status'        => self::STATUS_COMPLETED,
                        'completed_at'  => current_time('mysql'),
                        'error_message' => null,
                        'lock_key'      => null,
                        'lock_expiration' => null
                    ),
                    array('id' => $job_id),
                    array('%s', '%s', '%s', '%s', '%s'),
                    array('%d')
                );
                
                $this->log("Job ID {$job_id} completed successfully");
                do_action('ebp_job_completed', $job_id, $job->job_name, $job_data);
            } else {
                throw new Exception("Job handler returned failure status");
            }
            
        } catch (Exception $e) {
            $this->handle_job_failure($job, $e->getMessage());
        }
    }
    
    /**
     * Handle a failed job
     *
     * @param object $job The job object
     * @param string $error_message The error message
     */
    private function handle_job_failure($job, $error_message) {
        global $wpdb;
        
        $this->log("Job ID {$job->id} failed: {$error_message}");
        
        $retries = $job->retries + 1;
        
        if ($retries < self::MAX_RETRIES) {
            // Calculate backoff delay (exponential)
            $delay = pow(2, $retries) * 60; // 2, 4, 8, 16 minutes
            $scheduled_at = date('Y-m-d H:i:s', time() + $delay);
            
            // Reschedule job with backoff
            $wpdb->update(
                $this->table_name,
                array(
                    'status'        => self::STATUS_PENDING,
                    'retries'       => $retries,
                    'scheduled_at'  => $scheduled_at,
                    'error_message' => $error_message,
                    'lock_key'      => null,
                    'lock_expiration' => null
                ),
                array('id' => $job->id),
                array('%s', '%d', '%s', '%s', '%s', '%s'),
                array('%d')
            );
            
            $this->log("Job ID {$job->id} rescheduled for retry {$retries} at {$scheduled_at}");
            do_action('ebp_job_rescheduled', $job->id, $job->job_name, $retries, $scheduled_at);
            
        } else {
            // Mark as permanently failed
            $wpdb->update(
                $this->table_name,
                array(
                    'status'        => self::STATUS_FAILED,
                    'completed_at'  => current_time('mysql'),
                    'error_message' => $error_message,
                    'lock_key'      => null,
                    'lock_expiration' => null
                ),
                array('id' => $job->id),
                array('%s', '%s', '%s', '%s', '%s'),
                array('%d')
            );
            
            $this->log("Job ID {$job->id} permanently failed after {$retries} retries: {$error_message}");
            do_action('ebp_job_failed', $job->id, $job->job_name, $error_message);
        }
    }
    
    /**
     * Trigger asynchronous job processing via non-blocking HTTP request
     *
     * @param int|null $job_id Optional specific job ID to process
     */
    private function trigger_async_job_processing($job_id = null) {
        // Build the async request
        $url = admin_url('admin-ajax.php');
        
        $args = array(
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'body'      => array(
                'action' => 'process_background_job',
            )
        );
        
        // Add specific job ID if provided
        if ($job_id) {
            $args['body']['job_id'] = $job_id;
            
            // Add nonce for security (when triggering from admin)
            if (is_admin()) {
                $args['body']['nonce'] = wp_create_nonce('process_background_job');
            }
        }
        
        // Send the non-blocking request
        wp_remote_post($url, $args);
    }
    
    /**
     * Clean up old completed and failed jobs
     */
    public function cleanup_old_jobs() {
        global $wpdb;
        
        // Delete completed jobs older than 7 days
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} 
            WHERE status = %s 
            AND completed_at < %s",
            self::STATUS_COMPLETED,
            date('Y-m-d H:i:s', strtotime('-7 days'))
        ));
        
        // Delete failed jobs older than 30 days
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} 
            WHERE status = %s 
            AND completed_at < %s",
            self::STATUS_FAILED,
            date('Y-m-d H:i:s', strtotime('-30 days'))
        ));
        
        // Unlock jobs with expired locks
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table_name}
            SET status = %s,
                lock_key = NULL,
                lock_expiration = NULL
            WHERE status = %s
            AND lock_expiration < %s",
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            current_time('mysql')
        ));
    }
    
    /**
     * Log a message to the error log
     *
     * @param string $message The message to log
     */
    private function log($message) {
        error_log("[Enterprise Background Processor] " . $message);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Background Jobs', 'enterprise-background-processor'),
            __('Background Jobs', 'enterprise-background-processor'),
            'manage_options',
            'enterprise-background-processor',
            array($this, 'render_admin_page'),
            'dashicons-update',
            80
        );
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        global $wpdb;
        
        // Process actions
        if (isset($_GET['action']) && isset($_GET['job_id']) && check_admin_referer('ebp_job_action')) {
            $job_id = intval($_GET['job_id']);
            $action = sanitize_text_field($_GET['action']);
            
            switch ($action) {
                case 'retry':
                    $wpdb->update(
                        $this->table_name,
                        array(
                            'status'       => self::STATUS_PENDING,
                            'scheduled_at' => current_time('mysql'),
                            'retries'      => 0,
                            'error_message' => null,
                            'lock_key'     => null,
                            'lock_expiration' => null
                        ),
                        array('id' => $job_id),
                        array('%s', '%s', '%d', '%s', '%s', '%s'),
                        array('%d')
                    );
                    $this->trigger_async_job_processing($job_id);
                    break;
                    
                case 'cancel':
                    $wpdb->delete($this->table_name, array('id' => $job_id), array('%d'));
                    break;
            }
        }
        
        // Get status filter
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $valid_statuses = array(self::STATUS_PENDING, self::STATUS_PROCESSING, self::STATUS_COMPLETED, self::STATUS_FAILED);
        
        // Build query
        $query = "SELECT * FROM {$this->table_name}";
        $where = array();
        
        if ($status_filter && in_array($status_filter, $valid_statuses)) {
            $where[] = $wpdb->prepare("status = %s", $status_filter);
        }
        
        if (!empty($where)) {
            $query .= " WHERE " . implode(' AND ', $where);
        }
        
        $query .= " ORDER BY id DESC LIMIT 100";
        
        // Get jobs
        $jobs = $wpdb->get_results($query);
        
        // Get counts by status
        $counts = array();
        foreach ($valid_statuses as $status) {
            $counts[$status] = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE status = %s",
                $status
            ));
        }
        
        // Render view
        include(plugin_dir_path(__FILE__) . 'views/admin.php');
    }
}

// Initialize plugin
function enterprise_background_processor() {
    return Enterprise_Background_Processor::get_instance();
}
enterprise_background_processor();

// Admin view template
if (!file_exists(plugin_dir_path(__FILE__) . 'views/admin.php')) {
    // Create views directory if it doesn't exist
    if (!file_exists(plugin_dir_path(__FILE__) . 'views')) {
        mkdir(plugin_dir_path(__FILE__) . 'views');
    }
    
    // Create admin view template file
    file_put_contents(plugin_dir_path(__FILE__) . 'views/admin.php', '<?php
    if (!defined("ABSPATH")) {
        exit; // Exit if accessed directly
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__("Background Jobs", "enterprise-background-processor"); ?></h1>
        
        <nav class="nav-tab-wrapper wp-clearfix">
            <a href="?page=enterprise-background-processor" class="nav-tab <?php echo empty($status_filter) ? "nav-tab-active" : ""; ?>"><?php echo esc_html__("All", "enterprise-background-processor"); ?> <span class="count">(<?php echo array_sum($counts); ?>)</span></a>
            <?php foreach ($counts as $status => $count): ?>
                <a href="?page=enterprise-background-processor&status=<?php echo esc_attr($status); ?>" class="nav-tab <?php echo $status_filter === $status ? "nav-tab-active" : ""; ?>">
                    <?php echo esc_html(ucfirst($status)); ?> <span class="count">(<?php echo intval($count); ?>)</span>
                </a>
            <?php endforeach; ?>
        </nav>
        
        <div class="tablenav top">
            <div class="alignleft actions">
                <a href="?page=enterprise-background-processor&refresh=1" class="button"><?php echo esc_html__("Refresh", "enterprise-background-processor"); ?></a>
            </div>
        </div>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col"><?php echo esc_html__("ID", "enterprise-background-processor"); ?></th>
                    <th scope="col"><?php echo esc_html__("Job Name", "enterprise-background-processor"); ?></th>
                    <th scope="col"><?php echo esc_html__("Status", "enterprise-background-processor"); ?></th>
                    <th scope="col"><?php echo esc_html__("Priority", "enterprise-background-processor"); ?></th>
                    <th scope="col"><?php echo esc_html__("Created", "enterprise-background-processor"); ?></th>
                    <th scope="col"><?php echo esc_html__("Scheduled", "enterprise-background-processor"); ?></th>
                    <th scope="col"><?php echo esc_html__("Started", "enterprise-background-processor"); ?></th>
                    <th scope="col"><?php echo esc_html__("Completed", "enterprise-background-processor"); ?></th>
                    <th scope="col"><?php echo esc_html__("Retries", "enterprise-background-processor"); ?></th>
                    <th scope="col"><?php echo esc_html__("Actions", "enterprise-background-processor"); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($jobs)): ?>
                    <tr>
                        <td colspan="10"><?php echo esc_html__("No jobs found.", "enterprise-background-processor"); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($jobs as $job): ?>
                        <tr>
                            <td><?php echo esc_html($job->id); ?></td>
                            <td><?php echo esc_html($job->job_name); ?></td>
                            <td>
                                <span class="status-<?php echo esc_attr($job->status); ?>">
                                    <?php echo esc_html(ucfirst($job->status)); ?>
                                </span>
                                <?php if (!empty($job->error_message)): ?>
                                    <span class="dashicons dashicons-warning" title="<?php echo esc_attr($job->error_message); ?>"></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($job->priority); ?></td>
                            <td><?php echo !empty($job->created_at) ? esc_html(date_i18n(get_option("date_format") . " " . get_option("time_format"), strtotime($job->created_at))) : ""; ?></td>
                            <td><?php echo !empty($job->scheduled_at) ? esc_html(date_i18n(get_option("date_format") . " " . get_option("time_format"), strtotime($job->scheduled_at))) : ""; ?></td>
                            <td><?php echo !empty($job->started_at) ? esc_html(date_i18n(get_option("date_format") . " " . get_option("time_format"), strtotime($job->started_at))) : ""; ?></td>
                            <td><?php echo !empty($job->completed_at) ? esc_html(date_i18n(get_option("date_format") . " " . get_option("time_format"), strtotime($job->completed_at))) : ""; ?></td>
                            <td><?php echo esc_html($job->retries); ?></td>
                            <td>
                                <?php if ($job->status === "pending" || $job->status === "failed"): ?>
                                    <a href="<?php echo wp_nonce_url(add_query_arg(array("action" => "retry", "job_id" => $job->id)), "ebp_job_action"); ?>" class="button button-small"><?php echo esc_html__("Run Now", "enterprise-background-processor"); ?></a>
                                <?php endif; ?>
                                
                                <?php if ($job->status !== "completed"): ?>
                                    <a href="<?php echo wp_nonce_url(add_query_arg(array("action" => "cancel", "job_id" => $job->id)), "ebp_job_action"); ?>" class="button button-small" onclick="return confirm(\'<?php echo esc_js(__("Are you sure you want to delete this job?", "enterprise-background-processor")); ?>\');"><?php echo esc_html__("Cancel", "enterprise-background-processor"); ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <style>
        .status-pending { color: #ffba00; }
        .status-processing { color: #2271b1; }
        .status-completed { color: #46b450; }
        .status-failed { color: #dc3232; }
    </style>
    ');
}

// Example usage API

/**
 * Example of how to add a background job
 */
function ebp_example_enqueue_job() {
    // Get job processor instance
    $processor = enterprise_background_processor();
    
    // Enqueue a job
    $processor->enqueue_job('send_email_notification', array(
        'recipient' => 'user@example.com',
        'subject' => 'Your order has shipped',
        'template' => 'order_shipped',
        'order_id' => 12345
    ), 0, 3); // Priority 3 (high)
}

/**
 * Example implementation of a job handler
 */
add_action('ebp_job_send_email_notification', 'handle_email_notification_job', 10, 2);



function handle_email_notification_job($job_data, $job_id) {
    // Extract job data
    $recipient = isset($job_data['recipient']) ? sanitize_email($job_data['recipient']) : '';
    $subject = isset($job_data['subject']) ? sanitize_text_field($job_data['subject']) : '';
    $template = isset($job_data['template']) ? sanitize_key($job_data['template']) : '';
    $order_id = isset($job_data['order_id']) ? intval($job_data['order_id']) : 0;
    
    // Validate required data
    if (empty($recipient) || empty($subject) || empty($template)) {
        throw new Exception("Missing required email parameters");
    }
    
    // Log the job execution
    error_log("Processing email notification job #{$job_id} for order #{$order_id}");
    
    // Get email content based on template
    $content = get_email_template_content($template, $order_id);
    
    // Send the email
    $result = wp_mail($recipient, $subject, $content, array(
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
    ));
    
    if (!$result) {
        throw new Exception("Failed to send email to {$recipient}");
    }
    
    // Log successful completion
    error_log("Email notification job #{$job_id} completed successfully");
    
    return true;
}

/**
 * Helper function to get email template content
 */
function get_email_template_content($template, $order_id) {
    // Implementation would load template and replace variables
    // This is just a placeholder
    return '<html><body><h1>Your order #' . $order_id . ' has shipped!</h1><p>Thank you for your purchase.</p></body></html>';
}

/**
 * Example of how to schedule a recurring job
 */
function ebp_example_schedule_recurring_job() {
    // Get job processor instance
    $processor = enterprise_background_processor();
    
    // Schedule a recurring job
    $processor->schedule_recurring_job('sync_external_products', array(
        'source' => 'external_api',
        'limit' => 100
    ), 'hourly', 5);
}

/**
 * Example implementation of a recurring job handler
 */
add_action('ebp_job_sync_external_products', 'handle_product_sync_job', 10, 2);
function handle_product_sync_job($job_data, $job_id) {
    // Extract job data
    $source = isset($job_data['source']) ? sanitize_text_field($job_data['source']) : '';
    $limit = isset($job_data['limit']) ? intval($job_data['limit']) : 50;
    
    // Log start of sync
    error_log("Starting product sync job #{$job_id} from {$source}, limit: {$limit}");
    
    try {
        // Connect to external API (implementation not shown)
        $api_client = new External_API_Client($source);
        
        // Fetch products
        $products = $api_client->get_products($limit);
        
        // Process each product
        $processed = 0;
        foreach ($products as $product) {
            // Process product (implementation not shown)
            update_or_create_product($product);
            $processed++;
        }
        
        // Log successful completion
        error_log("Product sync job #{$job_id} completed: {$processed} products processed");
        
        return true;
        
    } catch (Exception $e) {
        // Log error and rethrow to trigger retry mechanism
        error_log("Product sync job #{$job_id} failed: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Example of a batch processing job with chunking
 */
function ebp_schedule_large_import($file_path, $batch_size = 100) {
    // Get the total number of records to process
    $total_records = count_records_in_file($file_path);
    
    // Calculate the number of batches needed
    $total_batches = ceil($total_records / $batch_size);
    
    $processor = enterprise_background_processor();
    
    // Schedule the first batch
    $processor->enqueue_job('process_import_batch', array(
        'file_path' => $file_path,
        'batch_number' => 1,
        'total_batches' => $total_batches,
        'batch_size' => $batch_size,
        'offset' => 0
    ), 0, 5);
    
    return $total_batches;
}

/**
 * Batch processing handler - processes one chunk and schedules the next
 */
add_action('ebp_job_process_import_batch', 'handle_import_batch', 10, 2);
function handle_import_batch($job_data, $job_id) {
    // Extract batch information
    $file_path = isset($job_data['file_path']) ? $job_data['file_path'] : '';
    $batch_number = isset($job_data['batch_number']) ? intval($job_data['batch_number']) : 1;
    $total_batches = isset($job_data['total_batches']) ? intval($job_data['total_batches']) : 1;
    $batch_size = isset($job_data['batch_size']) ? intval($job_data['batch_size']) : 100;
    $offset = isset($job_data['offset']) ? intval($job_data['offset']) : 0;
    
    // Validate file exists
    if (!file_exists($file_path)) {
        throw new Exception("Import file not found: {$file_path}");
    }
    
    // Log batch start
    error_log("Processing import batch {$batch_number}/{$total_batches} - Job #{$job_id}");
    
    try {
        // Process the current batch (implementation not shown)
        $records = read_records_from_file($file_path, $offset, $batch_size);
        $processed = process_import_records($records);
        
        // Log batch completion
        error_log("Import batch {$batch_number}/{$total_batches} completed - {$processed} records processed");
        
        // Schedule next batch if not complete
        if ($batch_number < $total_batches) {
            $processor = enterprise_background_processor();
            
            // Calculate new offset for next batch
            $new_offset = $offset + $batch_size;
            
            // Schedule the next batch
            $processor->enqueue_job('process_import_batch', array(
                'file_path' => $file_path,
                'batch_number' => $batch_number + 1,
                'total_batches' => $total_batches,
                'batch_size' => $batch_size,
                'offset' => $new_offset
            ), 60, 5); // 60 second delay to prevent server overload
        }
        
        return true;
        
    } catch (Exception $e) {
        // Log error and rethrow to trigger retry mechanism
        error_log("Import batch {$batch_number}/{$total_batches} failed: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Example of how to use the background processor with WP-CLI
 */
if (defined('WP_CLI') && WP_CLI) {
    /**
     * Background Processing CLI commands.
     */
    class EBP_CLI_Commands {
        /**
         * Process pending jobs.
         *
         * ## OPTIONS
         *
         * [--limit=<number>]
         * : Maximum number of jobs to process.
         * ---
         * default: 0
         * ---
         *
         * ## EXAMPLES
         *
         *     wp background-jobs process
         *     wp background-jobs process --limit=10
         *
         * @param array $args Command arguments.
         * @param array $assoc_args Command associated arguments.
         */
        public function process($args, $assoc_args) {
            global $wpdb;
            
            $processor = enterprise_background_processor();
            $table_name = $wpdb->prefix . 'background_jobs';
            
            $limit = isset($assoc_args['limit']) ? intval($assoc_args['limit']) : 0;
            
            // Get jobs ready for processing
            $where = "WHERE status = 'pending' AND scheduled_at <= '" . current_time('mysql') . "'";
            $limit_sql = $limit > 0 ? "LIMIT " . $limit : "";
            
            $job_ids = $wpdb->get_col("SELECT id FROM {$table_name} {$where} ORDER BY priority ASC, scheduled_at ASC {$limit_sql}");
            
            if (empty($job_ids)) {
                WP_CLI::success("No pending jobs to process.");
                return;
            }
            
            WP_CLI::log("Processing " . count($job_ids) . " jobs...");
            
            $progress = \WP_CLI\Utils\make_progress_bar('Processing jobs', count($job_ids));
            
            $success_count = 0;
            $failure_count = 0;
            
            foreach ($job_ids as $job_id) {
                // Get job details
                $job = $wpdb->get_row($wpdb->prepare(
                    "SELECT job_name FROM {$table_name} WHERE id = %d",
                    $job_id
                ));
                
                WP_CLI::log("Processing job #{$job_id}: {$job->job_name}");
                
                try {
                    // Process the job directly (not async)
                    $reflection = new ReflectionMethod($processor, 'process_job');
                    $reflection->setAccessible(true);
                    $reflection->invoke($processor, $job_id);
                    
                    // Check if job was successful
                    $status = $wpdb->get_var($wpdb->prepare(
                        "SELECT status FROM {$table_name} WHERE id = %d",
                        $job_id
                    ));
                    
                    if ($status === 'completed') {
                        $success_count++;
                    } else {
                        $failure_count++;
                    }
                } catch (Exception $e) {
                    WP_CLI::warning("Job #{$job_id} failed: " . $e->getMessage());
                    $failure_count++;
                }
                
                $progress->tick();
            }
            
            $progress->finish();
            
            WP_CLI::success("Processed " . count($job_ids) . " jobs. Success: {$success_count}, Failed: {$failure_count}");
        }
        
        /**
         * List background jobs.
         *
         * ## OPTIONS
         *
         * [--status=<status>]
         * : Filter by job status (pending, processing, completed, failed).
         *
         * [--limit=<number>]
         * : Maximum number of jobs to list.
         * ---
         * default: 20
         * ---
         *
         * [--format=<format>]
         * : Output format (table, csv, json, count, ids, yaml).
         * ---
         * default: table
         * ---
         *
         * ## EXAMPLES
         *
         *     wp background-jobs list
         *     wp background-jobs list --status=failed
         *     wp background-jobs list --format=json
         *
         * @param array $args Command arguments.
         * @param array $assoc_args Command associated arguments.
         */
        public function list($args, $assoc_args) {
            global $wpdb;
            
            $table_name = $wpdb->prefix . 'background_jobs';
            
            $status = isset($assoc_args['status']) ? sanitize_text_field($assoc_args['status']) : '';
            $limit = isset($assoc_args['limit']) ? intval($assoc_args['limit']) : 20;
            $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';
            
            // Build query
            $query = "SELECT * FROM {$table_name}";
            $where = array();
            
            if (!empty($status)) {
                $valid_statuses = array('pending', 'processing', 'completed', 'failed');
                if (in_array($status, $valid_statuses)) {
                    $where[] = $wpdb->prepare("status = %s", $status);
                }
            }
            
            if (!empty($where)) {
                $query .= " WHERE " . implode(' AND ', $where);
            }
            
            $query .= " ORDER BY id DESC LIMIT {$limit}";
            
            // Get jobs
            $jobs = $wpdb->get_results($query, ARRAY_A);
            
            if (empty($jobs)) {
                WP_CLI::success("No jobs found.");
                return;
            }
            
            // Format the output
            if ($format === 'count') {
                WP_CLI::log(count($jobs));
                return;
            }
            
            if ($format === 'ids') {
                WP_CLI::log(implode(' ', wp_list_pluck($jobs, 'id')));
                return;
            }
            
            // Define fields for output
            $fields = array(
                'id',
                'job_name',
                'status',
                'priority',
                'scheduled_at',
                'retries'
            );
            
            WP_CLI\Utils\format_items($format, $jobs, $fields);
        }
    }
    
    WP_CLI::add_command('background-jobs', 'EBP_CLI_Commands');
}