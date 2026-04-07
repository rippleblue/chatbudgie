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
define('CHATBUDGIE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CHATBUDGIE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CHATBUDGIE_INDEX_META_TABLE', 'chatbudgie_index_meta');
define('CHATBUDGIE_CHUNK_TABLE', 'chatbudgie_chunk_data');

use ChatBudgie\Vektor\Core\Config;
use ChatBudgie\Vektor\Services\Indexer;
use ChatBudgie\Vektor\Services\Searcher;
use ChatBudgie\Vektor\Services\Optimizer;

class ChatBudgie {
    private static $instance = null;
    public static string $dataDir = CHATBUDGIE_PLUGIN_DIR . '/data';
    public static int $embeddingDimension = 1536;
    public static string $embeddingAPI = 'https://chat.superbudgie.com/api/rag/embedding/v1';
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
        Config::setDimensions(self::$embeddingDimension);

        if (!file_exists(self::$dataDir)) {
            if (!wp_mkdir_p(self::$dataDir)) {
                error_log('ChatBudgie: Failed to create data directory at ' . self::$dataDir);
            }
        }
        Config::setDataDir(self::$dataDir);

        // Initialize Indexer and Searcher
        $this->indexer = new Indexer();
        $this->searcher = new Searcher();

        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_footer', array($this, 'render_chat_widget'));
        add_action('wp_ajax_chatbudgie_send_message', array($this, 'handle_send_message'));
        add_action('wp_ajax_nopriv_chatbudgie_send_message', array($this, 'handle_send_message'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));

        // Add cron job hook
        add_action('chatbudgie_daily_task', array($this, 'daily_task'));

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

        $table_name = $wpdb->prefix . CHATBUDGIE_INDEX_META_TABLE;
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

        $table_name = $wpdb->prefix . CHATBUDGIE_CHUNK_TABLE;
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

        $table_name = $wpdb->prefix . CHATBUDGIE_INDEX_META_TABLE;
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

        $table_name = $wpdb->prefix . CHATBUDGIE_INDEX_META_TABLE;

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

        $table_name = $wpdb->prefix . CHATBUDGIE_INDEX_META_TABLE;

        $result = $wpdb->delete(
            $table_name,
            array('post_id' => $post_id),
            array('%d')
        );

        return $result !== false;
    }

    /**
     * Plugin activation handler
     * Sets up scheduled tasks and builds the initial WordPress content index
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

        // Build full WordPress index
        $this->build_wordpress_index();
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
        $chunk_table = $wpdb->prefix . CHATBUDGIE_CHUNK_TABLE;
        $wpdb->delete($chunk_table, array('post_id' => $post_id), array('%d'));
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
        $chunk_table = $wpdb->prefix . CHATBUDGIE_CHUNK_TABLE;

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
     * Index a single post if its content has been updated since the last index
     *
     * @param int $post_id The WordPress post ID
     * @return bool True if indexed, false if skipped or failed
     */
    public function index_post($post_id) {
        // Get post data
        $post = get_post($post_id);
        if (!$post) {
            error_log('ChatBudgie: Post not found: ' . $post_id);
            return false;
        }

        // Skip if post is not published
        if ($post->post_status !== 'publish' || !in_array($post->post_type, array('post', 'page'))) {
            return false;
        }

        // Get post modified time
        $post_modified = $post->post_modified_gmt;

        // Get last index time
        $last_indexed = $this->get_post_index_time($post_id);

        // Skip if post hasn't been modified since last index
        if ($last_indexed && strtotime($post_modified) <= strtotime($last_indexed)) {
            error_log('ChatBudgie: Skipping post ' . $post_id . ' - not modified since last index');
            return false;
        }

        try {
            $title = $post->post_title;
            $content = $post->post_content;
            $excerpt = $post->post_excerpt;

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

            return true;
        } catch (Exception $e) {
            error_log('ChatBudgie: Failed to index post ' . $post_id . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Build a full WordPress index by embedding all published posts and pages
     * Queries WordPress content in batches, generates embeddings via API, and stores them in the vector index
     *
     * @return void
     */
    private function build_wordpress_index() {
        try {
            error_log('ChatBudgie starting to build full WordPress index');

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
                        $this->index_post($post_id);
                    }
                    wp_reset_postdata();
                }

                $paged++;

            } while ($query->have_posts());

            $stats = $this->indexer->getStats();
            error_log('ChatBudgie finished building full WordPress index: ' . $stats);
        } catch (Exception $e) {
            error_log('ChatBudgie error building full WordPress index: ' . $e->getMessage());
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
            'Content-Type' => 'application/json',
        );

        $response = wp_remote_post(self::$embeddingAPI, array(
            'headers' => $headers,
            'body' => json_encode($body),
            'timeout' => 30
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
            if ($embeddingDimension !== self::$embeddingDimension) {
                $errorMsg = sprintf(
                    'Embedding dimension mismatch: API returned %d dimensions, but configured %d dimensions',
                    $embeddingDimension,
                    self::$embeddingDimension
                );
                error_log('ChatBudgie: ' . $errorMsg);
                throw new Exception($errorMsg);
            }
        } else {
            error_log('ChatBudgie: Warning - embedding dimension not returned by API, expected ' . self::$embeddingDimension);
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
     * @return array Array of results containing 'id', 'score', and 'chunkText'
     * @throws Exception If embedding or search fails
     */
    public function search_index($query_text, $k = 5, $threshold = 0.7) {
        try {
            // Embed the query text
            $embedding_data = $this->get_embedding('', $query_text, '');

            // Get the first chunk's embedding as the query vector
            if (empty($embedding_data) || !isset($embedding_data[0]['embedding'])) {
                throw new Exception('Failed to generate query embedding');
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
            throw $e;
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

        $chunk_table = $wpdb->prefix . CHATBUDGIE_CHUNK_TABLE;

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

        // Delete vector index data
        $this->delete_index_data();

        // Drop index meta table
        $this->drop_index_meta_table();

        // Drop chunk data table
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

        $table_name = $wpdb->prefix . CHATBUDGIE_INDEX_META_TABLE;

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

        $table_name = $wpdb->prefix . CHATBUDGIE_CHUNK_TABLE;

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
            'chatbudgie-script',
            CHATBUDGIE_PLUGIN_URL . 'assets/js/chatbudgie.js',
            array('jquery'),
            CHATBUDGIE_VERSION,
            true
        );

        wp_localize_script('chatbudgie-script', 'chatbudgie_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
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

        if ($icon_type === 'custom' && !empty($custom_icon)) :
            ?><img src="<?php echo esc_url($custom_icon); ?>" alt="Chat" style="width: <?php echo $size; ?>px; height: <?php echo $size; ?>px;" /><?php
        elseif ($icon_type === 'robot') :
            ?><svg width="<?php echo $size; ?>" height="<?php echo $size; ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="<?php echo $stroke_width; ?>">
                <rect x="3" y="11" width="18" height="10" rx="2"></rect>
                <circle cx="12" cy="5" r="2"></circle>
                <path d="M12 7v4"></path>
                <line x1="8" y1="16" x2="8" y2="16"></line>
                <line x1="16" y1="16" x2="16" y2="16"></line>
            </svg><?php
        elseif ($icon_type === 'headphones') :
            ?><svg width="<?php echo $size; ?>" height="<?php echo $size; ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="<?php echo $stroke_width; ?>">
                <path d="M3 18v-6a9 9 0 0 1 18 0v6"></path>
                <path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"></path>
            </svg><?php
        elseif ($icon_type === 'message') :
            ?><svg width="<?php echo $size; ?>" height="<?php echo $size; ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="<?php echo $stroke_width; ?>">
                <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>
            </svg><?php
        elseif ($icon_type === 'budgie') :
            ?><svg width="<?php echo $size; ?>" height="<?php echo $size; ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="<?php echo $stroke_width; ?>" stroke-linecap="round" stroke-linejoin="round">
                <path d="M16 7h.01"/>
                <path d="M3.4 18H12a8 8 0 0 0 8-8V7a4 4 0 0 0-7.28-2.3L2 20"/>
                <path d="m20 7 2 .5-2 .5"/>
                <path d="M10 18v3"/>
                <path d="M14 17.75V21"/>
                <path d="M7 18a6 6 0 0 0 3.84-10.61"/>
            </svg><?php
        else :
            ?><svg width="<?php echo $size; ?>" height="<?php echo $size; ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="<?php echo $stroke_width; ?>">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
            </svg><?php
        endif;
    }

    /**
     * Render the chat widget HTML markup
     * Outputs the chat bubble toggle, container, header, message area, and input form
     * 
     * @return void
     */
    public function render_chat_widget() {
        $icon_type = get_option('chatbudgie_icon_type', 'default');
        $custom_icon = get_option('chatbudgie_custom_icon', '');
        ?>
        <div id="chatbudgie-widget" class="chatbudgie-widget">
            <div class="chatbudgie-toggle">
                <?php $this->render_icon($icon_type, $custom_icon, 'toggle'); ?>
            </div>
            <div class="chatbudgie-container">
                <div class="chatbudgie-header">
                    <div class="chatbudgie-header-icon">
                        <?php $this->render_icon($icon_type, $custom_icon, 'header'); ?>
                    </div>
                    <h3><?php echo esc_html__('ChatBudgie', 'chatbudgie'); ?></h3>
                    <button class="chatbudgie-close">&times;</button>
                </div>
                <div class="chatbudgie-messages"></div>
                <div class="chatbudgie-input-area">
                    <input type="text" class="chatbudgie-input" placeholder="<?php echo esc_attr__('Please enter your question...', 'chatbudgie'); ?>">
                    <button class="chatbudgie-send"><?php echo esc_html__('Send', 'chatbudgie'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Handle AJAX chat message requests
     * Receives user messages, searches the vector index, and returns relevant results
     * Hooked to wp_ajax_chatbudgie_send_message and wp_ajax_nopriv_chatbudgie_send_message
     *
     * @return void Outputs JSON response and exits
     */
    public function handle_send_message() {
        check_ajax_referer('chatbudgie_nonce', 'nonce');

        $message = sanitize_text_field($_POST['message'] ?? '');
        $conversation_history = $_POST['conversation_history'] ?? array();

        if (empty($message)) {
            wp_send_json_error(array('message' => 'Message cannot be empty'));
        }

        try {
            // Search the vector index for relevant content
            $search_results = $this->search_index($message, 5, 0.2);

            if (empty($search_results)) {
                wp_send_json_success(array(
                    'reply' => '<p>' . __('I could not find any relevant information to answer your question.', 'chatbudgie') . '</p>',
                    'results' => array()
                ));
                return;
            }

            // Build reply from search results in HTML format
            $reply = '<div class="chatbudgie-results">';
            foreach ($search_results as $index => $result) {
                if ($index > 0) {
                    $reply .= '<hr class="chatbudgie-result-divider">';
                }
                $reply .= '<div class="chatbudgie-result-item">';
                $reply .= '<div class="chatbudgie-result-meta">';
                $reply .= '<span class="chatbudgie-result-id">' . esc_html($result['id']) . '</span>';
                $reply .= '<span class="chatbudgie-result-score">Score: ' . number_format($result['score'], 3) . '</span>';
                $reply .= '</div>';
                $reply .= '<div class="chatbudgie-result-content">' . wp_kses_post($result['chunkText']) . '</div>';
                $reply .= '</div>';
            }
            $reply .= '</div>';

            wp_send_json_success(array(
                'reply' => $reply,
                'results' => $search_results
            ));

        } catch (Exception $e) {
            error_log('ChatBudgie handle_send_message error: ' . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Add admin menu page for plugin settings
     * Registers the settings page under WordPress Settings menu
     * 
     * @return void
     */
    public function add_admin_menu() {
        add_options_page(
            __('ChatBudgie Settings', 'chatbudgie'),
            __('ChatBudgie', 'chatbudgie'),
            'manage_options',
            'chatbudgie',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register plugin settings with WordPress
     * Defines all settings fields that can be saved via the settings page
     * 
     * @return void
     */
    public function register_settings() {
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
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('ChatBudgie Settings', 'chatbudgie'); ?></h1>
            <p style="background: #f0f0f0; padding: 10px; border-left: 4px solid #667eea;">
                <?php echo esc_html__('API URL is fixed to: http://localhost:5000/chat', 'chatbudgie'); ?>
            </p>
            <div style="background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; margin-bottom: 20px;">
                <h2 style="margin-top: 0; margin-bottom: 15px;"><?php echo esc_html__('Token Management', 'chatbudgie'); ?></h2>
                <div style="display: flex; align-items: center; gap: 20px;">
                    <div>
                        <p style="font-size: 16px; font-weight: 600; margin: 0;">
                            <?php echo esc_html__('Remaining Tokens:', 'chatbudgie'); ?> <span style="color: #667eea; font-size: 24px;"><?php echo esc_html(get_option('chatbudgie_tokens', 1000)); ?></span>
                        </p>
                        <p style="font-size: 12px; color: #666; margin: 5px 0 0;">
                            <?php echo esc_html__('Number of tokens available for API calls', 'chatbudgie'); ?>
                        </p>
                    </div>
                    <button type="button" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 6px; padding: 10px 20px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
                        <?php echo esc_html__('Recharge Tokens', 'chatbudgie'); ?>
                    </button>
                </div>
            </div>
            <form method="post" action="options.php">
                <?php settings_fields('chatbudgie_settings'); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php echo esc_html__('Chat Bubble Icon', 'chatbudgie'); ?></th>
                        <td>
                            <?php $icon_type = get_option('chatbudgie_icon_type', 'default'); ?>
                            <label style="display: block; margin-bottom: 10px;">
                                <input type="radio" name="chatbudgie_icon_type" value="default" <?php checked($icon_type, 'default'); ?> />
                                <span style="margin-left: 8px;"><?php echo esc_html__('Default Icon', 'chatbudgie'); ?></span>
                                <span style="margin-left: 10px; display: inline-block; width: 40px; height: 40px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 50%; vertical-align: middle; text-align: center; line-height: 40px;">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" style="vertical-align: middle;">
                                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                                    </svg>
                                </span>
                            </label>
                            <label style="display: block; margin-bottom: 10px;">
                                <input type="radio" name="chatbudgie_icon_type" value="robot" <?php checked($icon_type, 'robot'); ?> />
                                <span style="margin-left: 8px;"><?php echo esc_html__('Robot', 'chatbudgie'); ?></span>
                                <span style="margin-left: 10px; display: inline-block; width: 40px; height: 40px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 50%; vertical-align: middle; text-align: center; line-height: 40px;">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" style="vertical-align: middle;">
                                        <rect x="3" y="11" width="18" height="10" rx="2"></rect>
                                        <circle cx="12" cy="5" r="2"></circle>
                                        <path d="M12 7v4"></path>
                                        <line x1="8" y1="16" x2="8" y2="16"></line>
                                        <line x1="16" y1="16" x2="16" y2="16"></line>
                                    </svg>
                                </span>
                            </label>
                            <label style="display: block; margin-bottom: 10px;">
                                <input type="radio" name="chatbudgie_icon_type" value="headphones" <?php checked($icon_type, 'headphones'); ?> />
                                <span style="margin-left: 8px;"><?php echo esc_html__('Customer Service', 'chatbudgie'); ?></span>
                                <span style="margin-left: 10px; display: inline-block; width: 40px; height: 40px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 50%; vertical-align: middle; text-align: center; line-height: 40px;">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" style="vertical-align: middle;">
                                        <path d="M3 18v-6a9 9 0 0 1 18 0v6"></path>
                                        <path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"></path>
                                    </svg>
                                </span>
                            </label>
                            <label style="display: block; margin-bottom: 10px;">
                                <input type="radio" name="chatbudgie_icon_type" value="message" <?php checked($icon_type, 'message'); ?> />
                                <span style="margin-left: 8px;"><?php echo esc_html__('Message', 'chatbudgie'); ?></span>
                                <span style="margin-left: 10px; display: inline-block; width: 40px; height: 40px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 50%; vertical-align: middle; text-align: center; line-height: 40px;">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" style="vertical-align: middle;">
                                        <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>
                                    </svg>
                                </span>
                            </label>
                            <label style="display: block; margin-bottom: 10px;">
                                <input type="radio" name="chatbudgie_icon_type" value="budgie" <?php checked($icon_type, 'budgie'); ?> />
                                <span style="margin-left: 8px;"><?php echo esc_html__('小鸟 (Budgie)', 'chatbudgie'); ?></span>
                                <span style="margin-left: 10px; display: inline-block; width: 40px; height: 40px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 50%; vertical-align: middle; text-align: center; line-height: 40px;">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle;">
                                        <path d="M16 7h.01"/>
                                        <path d="M3.4 18H12a8 8 0 0 0 8-8V7a4 4 0 0 0-7.28-2.3L2 20"/>
                                        <path d="m20 7 2 .5-2 .5"/>
                                        <path d="M10 18v3"/>
                                        <path d="M14 17.75V21"/>
                                        <path d="M7 18a6 6 0 0 0 3.84-10.61"/>
                                    </svg>
                                </span>
                            </label>
                            <label style="display: block; margin-bottom: 10px;">
                                <input type="radio" name="chatbudgie_icon_type" value="custom" <?php checked($icon_type, 'custom'); ?> />
                                <span style="margin-left: 8px;"><?php echo esc_html__('自定义图标 URL', 'chatbudgie'); ?></span>
                            </label>
                            <div id="custom-icon-url" style="margin-left: 28px; margin-top: 10px; <?php echo $icon_type === 'custom' ? '' : 'display: none;'; ?>">
                                <input type="url" name="chatbudgie_custom_icon" value="<?php echo esc_attr(get_option('chatbudgie_custom_icon')); ?>" class="regular-text" placeholder="https://example.com/icon.svg" />
                                <p class="description"><?php echo esc_html__('输入自定义图标的 URL 地址（支持 SVG、PNG、JPG 格式）', 'chatbudgie'); ?></p>
                            </div>
                        </td>
                    </tr>
                <tr valign="top">
                    <th scope="row"><?php echo esc_html__('OpenRouter API 配置', 'chatbudgie'); ?></th>
                    <td>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php echo esc_html__('API Key', 'chatbudgie'); ?></th>
                                <td>
                                    <input type="password" name="chatbudgie_openrouter_api_key" value="<?php echo esc_attr(get_option('chatbudgie_openrouter_api_key')); ?>" class="regular-text" />
                                    <p class="description"><?php echo esc_html__('Your API key for authentication', 'chatbudgie'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var radios = document.querySelectorAll('input[name="chatbudgie_icon_type"]');
                    var customUrlDiv = document.getElementById('custom-icon-url');
                    radios.forEach(function(radio) {
                        radio.addEventListener('change', function() {
                            customUrlDiv.style.display = this.value === 'custom' ? 'block' : 'none';
                        });
                    });

                    // Token recharge functionality
                    var rechargeButton = document.querySelector('button[type="button"]');
                    if (rechargeButton) {
                        rechargeButton.addEventListener('click', function() {
                            var amount = prompt('<?php echo esc_js(__('Please enter the number of tokens to recharge:', 'chatbudgie')); ?>', '1000');
                            if (amount && !isNaN(amount) && amount > 0) {
                                var currentTokens = parseInt('<?php echo esc_js(get_option('chatbudgie_tokens', 1000)); ?>');
                                var newTokens = currentTokens + parseInt(amount);

                                // Create hidden field to store new token amount
                                var tokenField = document.getElementById('chatbudgie_tokens');
                                if (!tokenField) {
                                    tokenField = document.createElement('input');
                                    tokenField.type = 'hidden';
                                    tokenField.id = 'chatbudgie_tokens';
                                    tokenField.name = 'chatbudgie_tokens';
                                    document.querySelector('form').appendChild(tokenField);
                                }
                                tokenField.value = newTokens;

                                // Show success message
                                alert('<?php echo esc_js(__('Recharge successful!', 'chatbudgie')); ?> \n<?php echo esc_js(__('New token amount:', 'chatbudgie')); ?> ' + newTokens);

                                // Update display
                                var tokenDisplay = document.querySelector('span[style*="color: #667eea"]');
                                if (tokenDisplay) {
                                    tokenDisplay.textContent = newTokens;
                                }
                            }
                        });
                    }
                });
                </script>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
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