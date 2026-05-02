<?php
/**
 * Plugin Name: ChatBudgie
 * Plugin URI: https://example.com/chatbudgie
 * Description: Display a chat dialog on WordPress pages, allowing users to converse with a RAG-based Agent to get website-related answers
 * Version: 1.0.0
 * Author: Budgie Team
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: chatbudgie
 */

if (!defined('ABSPATH')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Load Action Scheduler library
if (file_exists(__DIR__ . '/lib/action-scheduler/action-scheduler.php')) {
    require_once __DIR__ . '/lib/action-scheduler/action-scheduler.php';
}

// Load local Vektor library
if (file_exists(__DIR__ . '/lib/Vektor/Core/Config.php')) {
    require_once __DIR__ . '/lib/Vektor/Core/Config.php';
    require_once __DIR__ . '/lib/Vektor/Core/HnswLogic.php';
    require_once __DIR__ . '/lib/Vektor/Core/Math.php';
    require_once __DIR__ . '/lib/Vektor/Storage/Binary/VectorFile.php';
    require_once __DIR__ . '/lib/Vektor/Storage/Binary/GraphFile.php';
    require_once __DIR__ . '/lib/Vektor/Storage/Binary/MetaFile.php';
    require_once __DIR__ . '/lib/Vektor/Services/Indexer.php';
    require_once __DIR__ . '/lib/Vektor/Services/Searcher.php';
    require_once __DIR__ . '/lib/Vektor/Services/Optimizer.php';
}

define('CHATBUDGIE_VERSION', '1.0.0');
define('CHATBUDGIE_APP_NAME', 'chatbudgie');
define('CHATBUDGIE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CHATBUDGIE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CHATBUDGIE_BASE_URL', 'https://chat.superbudgie.com/');
//define('CHATBUDGIE_BASE_URL', 'https://docker.internal:8443/');
//define('CHATBUDGIE_BASE_URL', 'https://localhost:8443/');

use ChatBudgie\Vektor\Core\Config;
use ChatBudgie\Vektor\Services\Indexer;
use ChatBudgie\Vektor\Services\Searcher;
use ChatBudgie\Vektor\Services\Optimizer;

class ChatBudgie {
    public const DATA_DIR = CHATBUDGIE_PLUGIN_DIR . '/data';
    public const EMBEDDING_DIMENSION = 1536;
    public const EMBEDDING_API = CHATBUDGIE_BASE_URL . 'api/rag/embedding/v1';
    public const CHAT_API = CHATBUDGIE_BASE_URL . 'api/rag/chat';
    public const REFRESH_APP_KEY_API = CHATBUDGIE_BASE_URL . 'api/app/refreshkey';
    public const INDEX_META_TABLE = 'chatbudgie_index_meta';
    public const CHUNK_TABLE = 'chatbudgie_chunk_data';

    private static $instance = null;
    private Indexer $indexer;
    private Searcher $searcher;

    /**
     * Get the singleton instance of ChatBudgie
     * 
     * @return ChatBudgie The singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor to initialize plugin hooks and configuration
     * Sets up WordPress actions, filters, and registration hooks
     */
    private function __construct() {
        // Initialize the vector index dimension and data directory
        Config::setDimensions(self::EMBEDDING_DIMENSION);

        if (!file_exists(self::DATA_DIR)) {
            if (!wp_mkdir_p(self::DATA_DIR)) {
                error_log('ChatBudgie: Failed to create data directory at ' . self::DATA_DIR);
            }
        }
        Config::setDataDir(self::DATA_DIR);

        // Initialize Indexer and Searcher
        $this->indexer = new Indexer();
        $this->searcher = new Searcher();

        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_action('wp_footer', array($this, 'render_chat_widget'));
        add_action('wp_ajax_chatbudgie_send_message_sse', array($this, 'handle_send_message_sse'));
        add_action('wp_ajax_nopriv_chatbudgie_send_message_sse', array($this, 'handle_send_message_sse'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_notices', array($this, 'show_index_status_notice'));
        add_action('admin_post_chatbudgie_rebuild_index', array($this, 'handle_manual_rebuild_index'));

        // Add login callback action
        add_action('admin_post_chatbudgie_login_callback', array($this, 'handle_login_callback'));
        add_action('admin_post_nopriv_chatbudgie_login_callback', array($this, 'handle_login_callback'));

        // Add cron job hook
        add_action('chatbudgie_daily_task', array($this, 'daily_task'));

        // Add Action Scheduler hooks for indexing
        add_action('chatbudgie_build_index', array($this, 'execute_build_index'));
        add_action('chatbudgie_index_single_post', array($this, 'execute_index_single_post'), 10, 1);

        // Hook into post save to schedule/remove indexing
        add_action('save_post', array($this, 'handle_post_save'), 10, 3);

        // Hook into post deletion and status changes to remove indexes
        add_action('before_delete_post', array($this, 'handle_post_delete'));

        // Set up cron job on plugin activation
        register_activation_hook(__FILE__, array($this, 'activate'));

        // Clean up cron job on plugin deactivation
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    /**
     * Create the index meta table for tracking post index times
     * Creates a custom WordPress table to store when each post was last indexed
     *
     * @return void
     */
    private function create_index_meta_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::INDEX_META_TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            last_indexed datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY post_id (post_id),
            KEY last_indexed (last_indexed)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        if ($wpdb->last_error) {
            error_log('ChatBudgie: Failed to create index meta table: ' . $wpdb->last_error);
        } else {
            error_log('ChatBudgie: Index meta table created successfully');
        }
    }

    /**
     * Create the chunk data table for storing chunk text for each post
     *
     * @return void
     */
    private function create_chunk_data_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::CHUNK_TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            chunk_id int(11) NOT NULL,
            chunk_text longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY post_chunk (post_id, chunk_id),
            KEY post_id (post_id)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        if ($wpdb->last_error) {
            error_log('ChatBudgie: Failed to create chunk data table: ' . $wpdb->last_error);
        } else {
            error_log('ChatBudgie: Chunk data table created successfully');
        }
    }

    /**
     * Update the index time for a specific post
     * Records when a post was last indexed in the meta table
     *
     * @param int $post_id The WordPress post ID
     * @return bool True on success, false on failure
     */
    public function update_post_index_time($post_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::INDEX_META_TABLE;
        $current_time = current_time('mysql');

        $result = $wpdb->replace(
            $table_name,
            array(
                'post_id' => $post_id,
                'last_indexed' => $current_time
            ),
            array('%d', '%s')
        );

        if ($result === false) {
            error_log('ChatBudgie: Failed to update index time for post ' . $post_id);
            return false;
        }

        return true;
    }

    /**
     * Get the last index time for a specific post
     *
     * @param int $post_id The WordPress post ID
     * @return string|null The last indexed datetime or null if not found
     */
    public function get_post_index_time($post_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::INDEX_META_TABLE;

        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT last_indexed FROM {$table_name} WHERE post_id = %d",
                $post_id
            )
        );

        return $result;
    }

    /**
     * Delete index time record for a specific post
     *
     * @param int $post_id The WordPress post ID
     * @return bool True on success, false on failure
     */
    public function delete_post_index_time($post_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::INDEX_META_TABLE;

        $result = $wpdb->delete(
            $table_name,
            array('post_id' => $post_id),
            array('%d')
        );

        return $result !== false;
    }

    /**
     * Plugin activation handler
     * Sets up scheduled tasks and schedules the initial WordPress content index build
     */
    public function activate() {
        // Create index meta table
        $this->create_index_meta_table();

        // Create chunk data table
        $this->create_chunk_data_table();

        // Clear existing cron jobs
        wp_clear_scheduled_hook('chatbudgie_daily_task');

        // Schedule daily task at 3:00 AM local time
        wp_schedule_event(strtotime('03:00:00'), 'daily', 'chatbudgie_daily_task');

        // Schedule immediate index build via Action Scheduler
        $this->schedule_index_build();
    }

    /**
     * Schedule WordPress index build via Action Scheduler
     * Schedules an immediate background task to build the full index
     * Deletes existing build and single post indexing actions from database before starting fresh
     *
     * @return int The action ID
     */
    public function schedule_index_build() {
        global $wpdb;

        // Delete all existing build and single post indexing actions via direct SQL for efficiency
        $table_name = $wpdb->prefix . 'actionscheduler_actions';
        $group_table = $wpdb->prefix . 'actionscheduler_groups';

        $wpdb->query(
            $wpdb->prepare(
                "DELETE a FROM {$table_name} a
                 INNER JOIN {$group_table} g ON a.group_id = g.group_id
                 WHERE g.slug = %s AND a.hook IN (%s, %s)",
                'chatbudgie',
                'chatbudgie_build_index',
                'chatbudgie_index_single_post'
            )
        );

        error_log('ChatBudgie: Deleted existing indexing actions from database before scheduling new build');

        // Schedule fresh action
        $action_id = as_enqueue_async_action('chatbudgie_build_index', array(), 'chatbudgie');

        error_log('ChatBudgie: Scheduled fresh index build with action ID: ' . $action_id);

        return $action_id;
    }

    /**
     * Get the current indexing status
     *
     * @return array Status information
     */
    public function get_index_status() {
        $error = '';
        $store = \ActionScheduler_Store::instance();

        // Get total count of all post indexing tasks (no status filter)
        $scheduled_count = $store->query_actions(array(
            'hook' => 'chatbudgie_index_single_post'
        ), 'count');

        // Get count of completed tasks
        $completed_count = $store->query_actions(array(
            'hook' => 'chatbudgie_index_single_post',
            'status' => \ActionScheduler_Store::STATUS_COMPLETE
        ), 'count');

        // Check build index task status
        $pending_build_actions = as_get_scheduled_actions(array(
            'hook' => 'chatbudgie_build_index',
            'status' => \ActionScheduler_Store::STATUS_PENDING
        ));

        $running_build_actions = as_get_scheduled_actions(array(
            'hook' => 'chatbudgie_build_index',
            'status' => \ActionScheduler_Store::STATUS_RUNNING
        ));

        $failed_build_actions = as_get_scheduled_actions(array(
            'hook' => 'chatbudgie_build_index',
            'status' => \ActionScheduler_Store::STATUS_FAILED
        ));

        // Determine status based on rules:
        // 1. If build task failed → 'failed'
        // 2. If build task is pending/running → 'pending'
        // 3. If build task done but post tasks still running → 'running'
        // 4. If all post tasks complete → 'completed'
        if (!empty($failed_build_actions)) {
            $status = 'failed';
            if (isset($failed_build_actions[0])) {
                $error = $failed_build_actions[0]->get_message();
            }
        } elseif (!empty($pending_build_actions) || !empty($running_build_actions)) {
            $status = 'pending';
        } elseif ($scheduled_count > 0 && $completed_count < $scheduled_count) {
            $status = 'running';
        } elseif ($scheduled_count > 0 && $completed_count >= $scheduled_count) {
            $status = 'completed';
        } else {
            $status = 'completed';
        }

        // Calculate progress (based on completed vs total)
        $progress = 0;
        if ($scheduled_count > 0) {
            $progress = min(100, round(($completed_count / $scheduled_count) * 100));
        } elseif ($status === 'completed') {
            $progress = 100;
        }

        return array(
            'status' => $status,
            'scheduled_posts_count' => $scheduled_count,
            'completed_posts_count' => $completed_count,
            'progress' => $progress,
            'error' => $error
        );
    }

    /**
     * Schedule a single post index via Action Scheduler
     * Checks if the post needs indexing before scheduling
     *
     * @param int $post_id The WordPress post ID
     * @return int|null The action ID or null if skipped
     */
    public function schedule_post_index($post_id) {
        // Check if post needs indexing
        $post = get_post($post_id);
        if (!$post) {
            error_log('ChatBudgie: Post not found for scheduling: ' . $post_id);
            return null;
        }

        // Skip if post is not published
        if ($post->post_status !== 'publish' || !in_array($post->post_type, array('post', 'page'))) {
            return null;
        }

        // Get post modified time
        $post_modified = $post->post_modified_gmt;

        // Get last index time
        $last_indexed = $this->get_post_index_time($post_id);

        // Skip if post hasn't been modified since last index
        if ($last_indexed && strtotime($post_modified) <= strtotime($last_indexed)) {
            error_log('ChatBudgie: Skipping scheduling post ' . $post_id . ' - not modified since last index');
            return null;
        }

        $action_id = as_enqueue_async_action('chatbudgie_index_single_post', array($post_id), 'chatbudgie');

        error_log('ChatBudgie: Scheduled post ' . $post_id . ' index with action ID: ' . $action_id);

        return $action_id;
    }

    /**
     * Handle post save event to schedule or remove indexing
     *
     * @param int $post_id The post ID
     * @param WP_Post $post The post object
     * @param bool $update Whether this is an existing post being updated
     * @return void
     */
    public function handle_post_save($post_id, $post, $update) {
        // Skip autosaves
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Skip revisions
        if (wp_is_post_revision($post_id)) {
            return;
        }

        // Only index posts and pages
        if (!in_array($post->post_type, array('post', 'page'))) {
            return;
        }

        // Post is published - schedule indexing
        if ($post->post_status === 'publish') {
            $this->schedule_post_index($post_id);

            error_log('ChatBudgie: Post saved, scheduling index ' . $post_id);
        } else {
            // Post is not published - remove index if exists
            $this->delete_post_vectors($post_id);
            $this->delete_post_chunks($post_id);
            $this->delete_post_index_time($post_id);

            error_log('ChatBudgie: Deleted index for unpublished post ' . $post_id . ' (status: ' . $post->post_status . ')');
        }
    }

    /**
     * Handle post deletion to remove index
     *
     * @param int $post_id The post ID being deleted
     * @return void
     */
    public function handle_post_delete($post_id) {
        // Delete vectors for this post
        $this->delete_post_vectors($post_id);

        // Delete chunk data
        $this->delete_post_chunks($post_id);

        // Delete index time record
        $this->delete_post_index_time($post_id);

        error_log('ChatBudgie: Deleted index for deleted post ' . $post_id);
    }

    /**
     * Delete all vector entries for a specific post
     *
     * @param int $post_id The WordPress post ID
     * @return void
     */
    private function delete_post_vectors($post_id) {
        global $wpdb;

        try {
            // Get chunk IDs from the chunk table for this post
            $chunk_table = $wpdb->prefix . self::CHUNK_TABLE;
            $chunk_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT chunk_id FROM {$chunk_table} WHERE post_id = %d",
                    $post_id
                )
            );

            if (!empty($chunk_ids)) {
                // Delete vectors for each chunk
                foreach ($chunk_ids as $chunk_id) {
                    $vector_id = $post_id . '_' . $chunk_id;
                    $this->indexer->delete($vector_id);
                }
                error_log('ChatBudgie: Deleted ' . count($chunk_ids) . ' vectors for post ' . $post_id);
            }
        } catch (Exception $e) {
            error_log('ChatBudgie: Error deleting vectors for post ' . $post_id . ': ' . $e->getMessage());
        }
    }

    /**
     * Execute the build index action (called by Action Scheduler)
     * Schedules all published posts for indexing via Action Scheduler
     *
     * @return void
     * @throws Exception If scheduling fails
     */
    public function execute_build_index() {
        error_log('ChatBudgie: Action Scheduler executing build_wordpress_index');

        try {
            $scheduled_count = 0;
            $skipped_count = 0;

            // Get all published posts page by page
            $paged = 1;
            $posts_per_page = 10;

            do {
                $args = array(
                    'post_type' => array('post', 'page'),
                    'post_status' => 'publish',
                    'posts_per_page' => $posts_per_page,
                    'paged' => $paged,
                    'orderby' => 'ID',
                    'order' => 'ASC'
                );

                $query = new WP_Query($args);

                if ($query->have_posts()) {
                    while ($query->have_posts()) {
                        $query->the_post();
                        $post_id = get_the_ID();
                        // Schedule each post indexing as a separate async task
                        $action_id = $this->schedule_post_index($post_id);
                        if ($action_id) {
                            $scheduled_count++;
                        } else {
                            $skipped_count++;
                        }
                    }
                    wp_reset_postdata();
                }

                $paged++;

            } while ($query->have_posts());

            error_log('ChatBudgie full index schedule task is completed. Post index tasks have been scheduled, indexing summary: ' . $scheduled_count . ' posts scheduled, ' . $skipped_count . ' posts skipped (already indexed)');
        } catch (Exception $e) {
            error_log('ChatBudgie full index schedule task failed: ' . $e->getMessage());
            // Re-throw exception so Action Scheduler knows this task failed
            throw new Exception('Full index schedule task failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Execute the single post index action (called by Action Scheduler)
     * Indexes a single post by embedding its content and storing vectors
     *
     * @param int $post_id The WordPress post ID
     * @return void
     * @throws Exception If indexing fails
     */
    public function execute_index_single_post($post_id) {
        error_log('ChatBudgie: Action Scheduler executing index_post for post ' . $post_id);

        // Get post data
        $post = get_post($post_id);
        if (!$post) {
            error_log('ChatBudgie: Post not found: ' . $post_id);
            throw new Exception('Post not found: ' . $post_id);
        }

        // Skip if post is not published
        if ($post->post_status !== 'publish' || !in_array($post->post_type, array('post', 'page'))) {
            error_log('ChatBudgie: Skipping post ' . $post_id . ' - not published or wrong post type');
            return;
        }

        // Get post modified time
        $post_modified = $post->post_modified_gmt;

        // Get last index time
        $last_indexed = $this->get_post_index_time($post_id);

        // Skip if post hasn't been modified since last index
        if ($last_indexed && strtotime($post_modified) <= strtotime($last_indexed)) {
            error_log('ChatBudgie: Skipping post ' . $post_id . ' - not modified since last index');
            return;
        }

        try {
            $title = wp_strip_all_tags($post->post_title);
            $content = wp_strip_all_tags(strip_shortcodes($post->post_content));
            $excerpt = wp_strip_all_tags(strip_shortcodes($post->post_excerpt));

            // Get embedding chunks from API
            $chunks = $this->get_embedding($title, $content, $excerpt);

            // Index each chunk
            foreach ($chunks as $chunk_index => $chunk) {
                $vector_id = $post_id . '_' . $chunk_index;

                // Check if vector_id exists in index, delete first if it does
                if ($this->indexer->delete($vector_id)) {
                    error_log('Deleted existing vector: ' . $vector_id . ' before re-indexing');
                }

                $this->indexer->insert($vector_id, $chunk['embedding']);
                error_log('Indexed chunk: ' . $vector_id . ' (' . strlen($chunk['chunkText']) . ' chars)');
            }

            // Save chunk text to database
            $this->update_post_chunks($post_id, $chunks);

            // Update index time for this post
            $this->update_post_index_time($post_id);
            error_log('ChatBudgie: Indexed post ' . $post_id . ' - ' . $title . ' (' . count($chunks) . ' chunks)');
        } catch (Exception $e) {
            error_log('ChatBudgie: Failed to index post ' . $post_id . ': ' . $e->getMessage());
            // Re-throw exception so Action Scheduler knows this task failed
            throw new Exception('Failed to index post ' . $post_id . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Delete all chunks and their text for a specific post
     *
     * @param int $post_id The WordPress post ID
     * @return void
     */
    private function delete_post_chunks($post_id) {
        // Delete old chunks from chunk data table
        global $wpdb;
        $chunk_table = $wpdb->prefix . self::CHUNK_TABLE;
        $wpdb->delete($chunk_table, array('post_id' => $post_id), array('%d'));
    }

    /**
     * Delete all index data for all posts
     * Deletes vector data, truncates the index meta table and chunk data table
     *
     * @return void
     */
    private function delete_all_index_data() {
        global $wpdb;

        error_log('ChatBudgie: Deleting all index data');

        // Delete vector index data (files)
        $this->delete_index_data();

        // Truncate index meta table
        $index_meta_table = $wpdb->prefix . self::INDEX_META_TABLE;
        $wpdb->query("TRUNCATE TABLE {$index_meta_table}");

        // Truncate chunk data table
        $chunk_table = $wpdb->prefix . self::CHUNK_TABLE;
        $wpdb->query("TRUNCATE TABLE {$chunk_table}");

        error_log('ChatBudgie: All index data deleted');
    }

    /**
     * Save chunk text to the database for a specific post
     *
     * @param int $post_id The WordPress post ID
     * @param array $chunks Array of chunks with 'chunkText' key
     * @return void
     */
    private function update_post_chunks($post_id, $chunks) {
        $this->delete_post_chunks($post_id);

        global $wpdb;
        $chunk_table = $wpdb->prefix . self::CHUNK_TABLE;

        foreach ($chunks as $chunk_index => $chunk) {
            $wpdb->insert(
                $chunk_table,
                array(
                    'post_id' => $post_id,
                    'chunk_id' => $chunk_index,
                    'chunk_text' => $chunk['chunkText'] ?? ''
                ),
                array('%d', '%d', '%s')
            );
        }
    }

    /**
     * Get embedding vectors from the RAG embedding API
     * Sends content to the embedding API and returns chunked embeddings with their text
     * 
     * @param string $title The post title
     * @param string $content The post content
     * @param string $excerpt The post excerpt
     * @return array Array of chunks containing 'chunkText' and 'embedding'
     * @throws Exception If API request fails or returns invalid response
     */
    private function get_embedding($title, $content, $excerpt) {

        $body = array(
            'title' => $title,
            'excerpt' => $excerpt,
            'content' => $content,
            'contentType' => 'text/html'
        );

        $headers = array(
            'Content-Type: application/json',
            'appKey: ' . get_option('chatbudgie_app_key', ''),
        );

        $response = wp_remote_post(self::EMBEDDING_API, array(
            'headers' => $headers,
            'body' => json_encode($body),
            'timeout' => 60
        ));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            throw new Exception('API request failed: ' . $error_message);
        }

        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        if (isset($data['code']) && $data['code'] != 200) {
            $error_msg = isset($data['message']) ? $data['message'] : 'Unknown API error';
            throw new Exception('API error: ' . $error_msg);
        }

        // Check the embedding dimension
        if (isset($data['data']) && isset($data['data']['embeddingDimension'])) {
            $embeddingDimension = $data['data']['embeddingDimension'];
            if ($embeddingDimension !== self::EMBEDDING_DIMENSION) {
                $errorMsg = sprintf(
                    'Embedding dimension mismatch: API returned %d dimensions, but configured %d dimensions',
                    $embeddingDimension,
                    self::EMBEDDING_DIMENSION
                );
                error_log('ChatBudgie: ' . $errorMsg);
                throw new Exception($errorMsg);
            }
        } else {
            error_log('ChatBudgie: Warning - embedding dimension not returned by API, expected ' . self::EMBEDDING_DIMENSION);
        }

        // Extract embedding from the response
        // Response structure: data.chunks[0].embedding
        if (!isset($data['data']) || !isset($data['data']['chunks'])) {
            throw new Exception('Invalid response format: embedding not found');
        }

        $chunks = $data['data']['chunks'];

        // Return the chunks array (contains chunkText and embedding for each chunk)
        return $chunks;
    }

    /**
     * Search the vector index for similar content
     * Embeds the query text and returns top K chunks with scores above the threshold
     *
     * @param string $query_text The search query text
     * @param int $k Maximum number of results to return (default: 5)
     * @param float $threshold Minimum similarity score threshold (default: 0.7)
     * @return array Array of results containing 'id', 'score', and 'chunkText'. Returns empty array on error.
     */
    public function search_index($query_text, $k = 5, $threshold = 0.7) {
        try {
            // Embed the query text
            $embedding_data = $this->get_embedding('', $query_text, '');

            // Get the first chunk's embedding as the query vector
            if (empty($embedding_data) || !isset($embedding_data[0]['embedding'])) {
                error_log('ChatBudgie search_index error: Failed to generate query embedding');
                return array();
            }

            $query_vector = $embedding_data[0]['embedding'];

            // Search for top K results (oversample to handle threshold filtering)
            $results = $this->searcher->search($query_vector, $k, false);

            // Filter by threshold and limit to K
            $filtered_results = array();
            foreach ($results as $result) {
                if ($result['score'] >= $threshold) {
                    // Get chunk text from database
                    $chunk_text = $this->get_chunk_text($result['id']);

                    $filtered_results[] = array(
                        'id' => $result['id'],
                        'score' => $result['score'],
                        'chunkText' => $chunk_text
                    );

                    if (count($filtered_results) >= $k) {
                        break;
                    }
                }
            }

            return $filtered_results;

        } catch (Exception $e) {
            error_log('ChatBudgie search_index error: ' . $e->getMessage());
            // Return empty array instead of throwing exception
            return array();
        }
    }

    /**
     * Get chunk text by vector ID from the database
     *
     * @param string $vector_id The vector ID (e.g., '123_0')
     * @return string The chunk text or empty string if not found
     */
    private function get_chunk_text($vector_id) {
        global $wpdb;

        $chunk_table = $wpdb->prefix . self::CHUNK_TABLE;

        // Parse vector_id to get post_id and chunk_id
        $parts = explode('_', $vector_id);
        if (count($parts) < 2) {
            return '';
        }

        $post_id = (int) $parts[0];
        $chunk_id = (int) $parts[1];

        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT chunk_text FROM {$chunk_table} WHERE post_id = %d AND chunk_id = %d",
                $post_id,
                $chunk_id
            )
        );

        return $result ?: '';
    }

    /**
     * Plugin deactivation handler
     * Cleans up scheduled cron jobs and deletes vector index data
     *
     * @return void
     */
    public function deactivate() {
        // Clean up cron jobs
        wp_clear_scheduled_hook('chatbudgie_daily_task');

        // Delete all index data (vectors + truncate tables)
        $this->delete_all_index_data();

        // Drop tables
        $this->drop_index_meta_table();
        $this->drop_chunk_data_table();

        error_log('ChatBudgie plugin deactivated, cron jobs cleaned up, index data deleted');
    }

    /**
     * Drop the index meta table on plugin deactivation
     *
     * @return void
     */
    private function drop_index_meta_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::INDEX_META_TABLE;

        $wpdb->query("DROP TABLE IF EXISTS {$table_name}");

        if ($wpdb->last_error) {
            error_log('ChatBudgie: Failed to drop index meta table: ' . $wpdb->last_error);
        } else {
            error_log('ChatBudgie: Index meta table dropped successfully');
        }
    }

    /**
     * Drop the chunk data table on plugin deactivation
     *
     * @return void
     */
    private function drop_chunk_data_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::CHUNK_TABLE;

        $wpdb->query("DROP TABLE IF EXISTS {$table_name}");

        if ($wpdb->last_error) {
            error_log('ChatBudgie: Failed to drop chunk data table: ' . $wpdb->last_error);
        } else {
            error_log('ChatBudgie: Chunk data table dropped successfully');
        }
    }

    /**
     * Delete all vector index data by removing the entire data directory
     * 
     * @return void
     */
    private function delete_index_data() {
        $dataDir = Config::getDataDir();
        
        if (!is_dir($dataDir)) {
            return;
        }
        
        // Recursively remove directory using PHP functions
        $this->rrmdir($dataDir);
        
        if (!is_dir($dataDir)) {
            error_log('ChatBudgie: Deleted index data directory: ' . $dataDir);
        } else {
            error_log('ChatBudgie: Failed to delete index data directory: ' . $dataDir);
        }
    }

    /**
     * Recursively remove a directory and its contents
     * 
     * @param string $dir Directory path to remove
     * @return void
     */
    private function rrmdir($dir) {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Daily cron task handler
     * Runs vector index optimization to maintain search performance
     * 
     * @return void
     */
    public function daily_task() {
        try {
            // Log task start
            error_log('ChatBudgie daily task started at ' . current_time('Y-m-d H:i:s'));
            
            // Run vektor optimization task
            $optimizer = new Optimizer();
            $optimizer->run();
            
            // Log task completion
            error_log('ChatBudgie daily task completed at ' . current_time('Y-m-d H:i:s'));
        } catch (\Exception $e) {
            // Log task failure
            error_log('ChatBudgie daily task failed: ' . $e->getMessage() . ' at ' . current_time('Y-m-d H:i:s'));
        }
    }

    /**
     * Enqueue frontend scripts and styles
     * Loads CSS, JavaScript, and passes PHP variables to the frontend via wp_localize_script
     * 
     * @return void
     */
    public function enqueue_scripts() {
        wp_enqueue_style(
            'chatbudgie-style',
            CHATBUDGIE_PLUGIN_URL . 'assets/css/chatbudgie.css',
            array(),
            CHATBUDGIE_VERSION
        );

        wp_enqueue_script(
            'marked-js',
            'https://cdn.jsdelivr.net/npm/marked/marked.min.js',
            array(),
            '12.0.0',
            true
        );

        wp_enqueue_script(
            'chatbudgie-script',
            CHATBUDGIE_PLUGIN_URL . 'assets/js/chatbudgie.js',
            array('jquery', 'marked-js'),
            CHATBUDGIE_VERSION,
            true
        );

        wp_localize_script('chatbudgie-script', 'chatbudgie_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'sse_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('chatbudgie_nonce'),
            'strings' => array(
                'placeholder' => __('Please enter your question...', 'chatbudgie'),
                'sending' => __('Sending...', 'chatbudgie'),
                'error' => __('Failed to send, please try again', 'chatbudgie'),
                'api_error' => __('API call failed', 'chatbudgie')
            )
        ));
    }

    /**
     * Enqueue admin scripts and styles
     * 
     * @param string $hook The current admin page hook
     * @return void
     */
    public function admin_enqueue_scripts($hook) {
        // Only load on our settings page
        if (strpos($hook, 'chatbudgie') === false) {
            return;
        }

        wp_enqueue_style(
            'chatbudgie-admin-style',
            CHATBUDGIE_PLUGIN_URL . 'assets/css/chatbudgie-admin.css',
            array(),
            CHATBUDGIE_VERSION
        );
    }

    /**
     * Render SVG or custom icon based on icon type and context
     * Outputs inline SVG or img tag for chat widget icons
     * 
     * @param string $icon_type The type of icon to render (default, robot, headphones, message, budgie, custom)
     * @param string $custom_icon URL of custom icon (used when icon_type is 'custom')
     * @param string $context Display context ('toggle' for widget button, 'header' for chat header)
     * @return void
     */
    private function render_icon($icon_type, $custom_icon, $context = 'toggle') {
        $size = $context === 'header' ? 20 : 24;
        $stroke_width = $context === 'header' ? 1.5 : 2;

        include CHATBUDGIE_PLUGIN_DIR . 'templates/icons.php';
    }

    /**
     * Render the chat widget HTML markup
     * Outputs the chat bubble toggle, container, header, message area, and input form
     * 
     * @return void
     */
    public function render_chat_widget() {
        include CHATBUDGIE_PLUGIN_DIR . 'templates/chat-widget.php';
    }

    /**
     * Handle AJAX chat message requests with SSE streaming
     * Receives user messages, searches the vector index, and streams response via SSE
     * Hooked to wp_ajax_chatbudgie_send_message_sse and wp_ajax_nopriv_chatbudgie_send_message_sse
     *
     * @return void Outputs SSE stream and exits
     */
    public function handle_send_message_sse() {
        check_ajax_referer('chatbudgie_nonce', 'nonce');

        $message = sanitize_text_field($_POST['message'] ?? '');
        $conversation_history_raw = $_POST['conversation_history'] ?? '[]';
        $conversation_history = json_decode(stripslashes($conversation_history_raw), true) ?: array();

        // Set headers for SSE
        $this->sse_set_headers();

        if (empty($message)) {
            echo "data:{\"error\":\"Message cannot be empty\"}\n\n";
            flush();
            exit;
        }

        try {
            // Search the vector index for relevant content (returns empty array on error)
            $search_results = $this->search_index($message, 5, 0.2);

            // Build context from search results
            $context = array();
            if (!empty($search_results)) {
                foreach ($search_results as $result) {
                    $context[] = array(
                        'score' => $result['score'],
                        'text' => $result['chunkText']
                    );
                }
            }

            // Make request to chat API
            $chat_request = array(
                'context' => $context,
                'messages' => $conversation_history
            );

            // Call the streaming chat API
            $this->stream_api_response(self::CHAT_API, $chat_request);

        } catch (Exception $e) {
            error_log('ChatBudgie handle_send_message_sse error: ' . $e->getMessage());
            echo "data:{\"error\":\"" . addslashes($e->getMessage()) . "\"}\n\n";
            flush();
        }

        exit;
    }

    /**
     * Stream API response and forward to client via SSE
     *
     * @param string $url The API endpoint URL
     * @param array $body The request body
     * @return void
     */
    private function stream_api_response($url, $body) {
        $headers = array(
            'Content-Type: application/json',
            'appKey: ' . get_option('chatbudgie_app_key', ''),
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        //curl_setopt($ch, CURLOPT_VERBOSE, true);
        
        // Enable streaming
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
            // Forward data directly to client
            echo $data;
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
            
            return strlen($data);
        });
        
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            error_log('ChatBudgie API stream error: ' . curl_error($ch));
            echo "data:{\"error\":\"API stream error: " . addslashes(curl_error($ch)) . "\"}\n\n";
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
        }
        
        curl_close($ch);
    }

    /**
     * Set HTTP headers for SSE streaming
     *
     * @return void
     */
    private function sse_set_headers() {
        // Clear all output buffering levels
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Disable Nginx buffering
        header('Content-Encoding: none'); // Disable compression for SSE

        // Prevent PHP from timing out
        set_time_limit(0);
    }

    /**
     * Send an SSE event to the client
     *
     * @param array $data The data to send
     * @return void
     */
    private function sse_send_event($data) {
        echo "data: " . json_encode($data) . "\n\n";
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }

    /**
     * Forward raw SSE data line to the client
     *
     * @param string $line The raw SSE data line (e.g., "data:The weather")
     * @return void
     */
    private function sse_send_event_raw($line) {
        echo $line . "\n";
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }

    /**
     * Show admin notice for index status
     * Displays the current status of background indexing tasks
     *
     * @return void
     */
    public function show_index_status_notice() {
        $status = $this->get_index_status();

        if ($status['status'] === 'pending') {
            ?>
            <div class="notice notice-info is-dismissible">
                <p>
                    <strong><?php echo esc_html__('ChatBudgie:', 'chatbudgie'); ?></strong>
                    <?php echo esc_html__('Index build is scheduled and will start shortly.', 'chatbudgie'); ?>
                </p>
            </div>
            <?php
        } elseif ($status['status'] === 'running') {
            $completed = $status['completed_posts_count'];
            $scheduled = $status['scheduled_posts_count'];
            $progress = $status['progress'];
            ?>
            <div class="notice notice-info is-dismissible">
                <p>
                    <strong><?php echo esc_html__('ChatBudgie:', 'chatbudgie'); ?></strong>
                    <?php
                    printf(
                        esc_html__('Indexing progress: %d of %d posts completed (%d%%)', 'chatbudgie'),
                        intval($completed),
                        intval($scheduled),
                        intval($progress)
                    );
                    ?>
                </p>
                <progress value="<?php echo esc_attr($progress); ?>" max="100" style="width: 100%; height: 20px;"></progress>
            </div>
            <?php
        } elseif ($status['status'] === 'completed') {
            ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <strong><?php echo esc_html__('ChatBudgie:', 'chatbudgie'); ?></strong>
                    <?php echo esc_html__('Index build completed successfully.', 'chatbudgie'); ?>
                </p>
            </div>
            <?php
        } elseif ($status['status'] === 'failed') {
            $error_msg = isset($status['error']) ? $status['error'] : 'Unknown error';
            ?>
            <div class="notice notice-error is-dismissible">
                <p>
                    <strong><?php echo esc_html__('ChatBudgie:', 'chatbudgie'); ?></strong>
                    <?php
                    echo esc_html__('Index build failed:', 'chatbudgie') . ' ' . esc_html($error_msg);
                    echo ' <a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=chatbudgie_rebuild_index'), 'chatbudgie_rebuild_index')) . '">' . esc_html__('Try again', 'chatbudgie') . '</a>';
                    ?>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Handle manual index rebuild request
     *
     * @return void
     */
    public function handle_manual_rebuild_index() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        // Clear all existing index data
        $this->delete_all_index_data();

        // Schedule fresh index build
        $this->schedule_index_build();

        wp_redirect(wp_get_referer() ? wp_get_referer() : admin_url('options-general.php?page=chatbudgie'));
        exit;
    }

    /**
     * Add admin menu page for plugin settings
     * Registers the settings page under WordPress Settings menu
     * 
     * @return void
     */
    public function add_admin_menu() {
        // Main Menu
        add_menu_page(
            __('ChatBudgie', 'chatbudgie'),
            __('ChatBudgie', 'chatbudgie'),
            'manage_options',
            'chatbudgie',
            array($this, 'render_settings_page'),
            'dashicons-format-chat',
            25
        );

        // Subpages
        add_submenu_page(
            'chatbudgie',
            __('Settings', 'chatbudgie'),
            __('Settings', 'chatbudgie'),
            'manage_options',
            'chatbudgie',
            array($this, 'render_settings_page')
        );

        add_submenu_page(
            'chatbudgie',
            __('Activity', 'chatbudgie'),
            __('Activity', 'chatbudgie'),
            'manage_options',
            'chatbudgie-activity',
            array($this, 'render_activity_page')
        );

        add_submenu_page(
            'chatbudgie',
            __('Orders', 'chatbudgie'),
            __('Orders', 'chatbudgie'),
            'manage_options',
            'chatbudgie-orders',
            array($this, 'render_orders_page')
        );
    }

    /**
     * Render the activity page
     * 
     * @return void
     */
    public function render_activity_page() {
        echo '<div class="wrap"><h1>' . esc_html__('Chat Activity', 'chatbudgie') . '</h1><p>' . esc_html__('Coming soon...', 'chatbudgie') . '</p></div>';
    }

    /**
     * Render the orders page
     * 
     * @return void
     */
    public function render_orders_page() {
        echo '<div class="wrap"><h1>' . esc_html__('Orders', 'chatbudgie') . '</h1><p>' . esc_html__('Coming soon...', 'chatbudgie') . '</p></div>';
    }

    /**
     * Handle the login callback from the login server
     * 
     * @return void
     */
    public function handle_login_callback() {
        $code = sanitize_text_field($_GET['code'] ?? '');

        if (empty($code)) {
            wp_die(__('Authorization code is missing', 'chatbudgie'), __('Login Error', 'chatbudgie'), array('response' => 400));
        }

        // Call the refresh appkey API
        $response = wp_remote_post(self::REFRESH_APP_KEY_API, array(
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
            'body'    => array(
                'code'    => $code,
                'appName' => CHATBUDGIE_APP_NAME,
                'siteUrl' => get_site_url(),
            ),
            'timeout' => 30,
            //'sslverify' => false,
        ));

        if (is_wp_error($response)) {
            wp_die($response->get_error_message(), __('API Error', 'chatbudgie'), array('response' => 500));
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        // Extract appkey from response. Try different common keys.
        $app_key = '';
        if (isset($body['data']['appKey'])) {
            $app_key = $body['data']['appKey'];
        } elseif (isset($body['data']['app_key'])) {
            $app_key = $body['data']['app_key'];
        } elseif (isset($body['appKey'])) {
            $app_key = $body['appKey'];
        } elseif (isset($body['app_key'])) {
            $app_key = $body['app_key'];
        }

        if ($app_key) {
            update_option('chatbudgie_app_key', $app_key);
            
            // Redirect to settings page
            wp_redirect(admin_url('admin.php?page=chatbudgie'));
            exit;
        }

        wp_die(__('Failed to retrieve appKey from login server', 'chatbudgie'), __('Login Error', 'chatbudgie'), array('response' => 500));
    }

    /**
     * Render the login page to authenticate user and set appKey
     * 
     * @return void
     */
    private function render_login_page() {
        include CHATBUDGIE_PLUGIN_DIR . 'templates/admin-login.php';
    }

    /**
     * Register plugin settings with WordPress
     * Defines all settings fields that can be saved via the settings page
     * 
     * @return void
     */
    public function register_settings() {
        register_setting('chatbudgie_settings', 'chatbudgie_app_key');
        register_setting('chatbudgie_settings', 'chatbudgie_icon_type');
        register_setting('chatbudgie_settings', 'chatbudgie_custom_icon');
        register_setting('chatbudgie_settings', 'chatbudgie_primary_color');
        register_setting('chatbudgie_settings', 'chatbudgie_secondary_color');
        register_setting('chatbudgie_settings', 'chatbudgie_tokens');
        register_setting('chatbudgie_settings', 'chatbudgie_openrouter_api_key');
        register_setting('chatbudgie_settings', 'chatbudgie_openrouter_model');
    }

    /**
     * Render the plugin settings page HTML
     * Displays the admin settings form with icon selection, token management, and API configuration
     * 
     * @return void
     */
    public function render_settings_page() {
        // Check if appKey is set
        $app_key = get_option('chatbudgie_app_key', '');
        
        if (empty($app_key)) {
            $this->render_login_page();
            return;
        }
        
        include CHATBUDGIE_PLUGIN_DIR . 'templates/admin-settings.php';
    }
}

/**
 * Helper function to get the ChatBudgie singleton instance
 * 
 * @return ChatBudgie The singleton instance
 */
function ChatBudgie() {
    return ChatBudgie::get_instance();
}

// Initialize the plugin
ChatBudgie();
