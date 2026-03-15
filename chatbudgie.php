<?php
/**
 * Plugin Name: ChatBudgie
 * Plugin URI: https://example.com/chatbudgie
 * Description: 在 WordPress 页面上显示聊天对话框，用户可以通过对话框与基于 RAG 的 Agent 对话，获得与网站相关的回答
 * Version: 1.0.0
 * Author: Budgie Team
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: chatbudgie
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CHATBUDGIE_VERSION', '1.0.0');
define('CHATBUDGIE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CHATBUDGIE_PLUGIN_URL', plugin_dir_url(__FILE__));

class ChatBudgie {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_footer', array($this, 'render_chat_widget'));
        add_action('wp_ajax_chatbudgie_send_message', array($this, 'handle_send_message'));
        add_action('wp_ajax_nopriv_chatbudgie_send_message', array($this, 'handle_send_message'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }

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
                'placeholder' => __('请输入您的问题...', 'chatbudgie'),
                'sending' => __('发送中...', 'chatbudgie'),
                'error' => __('发送失败，请重试', 'chatbudgie'),
                'api_error' => __('API调用失败', 'chatbudgie')
            )
        ));
    }

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
                    <input type="text" class="chatbudgie-input" placeholder="<?php echo esc_attr__('请输入您的问题...', 'chatbudgie'); ?>">
                    <button class="chatbudgie-send"><?php echo esc_html__('发送', 'chatbudgie'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    public function handle_send_message() {
        check_ajax_referer('chatbudgie_nonce', 'nonce');

        $message = sanitize_text_field($_POST['message'] ?? '');
        $conversation_history = $_POST['conversation_history'] ?? array();

        if (empty($message)) {
            wp_send_json_error(array('message' => '消息不能为空'));
        }

        $api_url = 'http://localhost:5000/chat';
        //$api_url = 'http://host.docker.internal:5000/chat';

        $body = array(
            'message' => $message,
            'conversation_history' => $conversation_history
        );

        $headers = array(
            'Content-Type' => 'application/json'
        );

        $response = wp_remote_post($api_url, array(
            'headers' => $headers,
            'body' => json_encode($body),
            'timeout' => 60
        ));

        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()));
        }

        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        if (isset($data['reply'])) {
            $reply = $data['reply'];
            wp_send_json_success(array('reply' => $reply));
        } else {
            $error_message = isset($data['error']) ? $data['error'] : '未知错误';
            wp_send_json_error(array('message' => $error_message));
        }
    }

    public function add_admin_menu() {
        add_options_page(
            __('ChatBudgie 设置', 'chatbudgie'),
            __('ChatBudgie', 'chatbudgie'),
            'manage_options',
            'chatbudgie',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting('chatbudgie_settings', 'chatbudgie_icon_type');
        register_setting('chatbudgie_settings', 'chatbudgie_custom_icon');
        register_setting('chatbudgie_settings', 'chatbudgie_primary_color');
        register_setting('chatbudgie_settings', 'chatbudgie_secondary_color');
        register_setting('chatbudgie_settings', 'chatbudgie_tokens');
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('ChatBudgie 设置', 'chatbudgie'); ?></h1>
            <p style="background: #f0f0f0; padding: 10px; border-left: 4px solid #667eea;">
                <?php echo esc_html__('API 地址已固定为: http://localhost:5000/chat', 'chatbudgie'); ?>
            </p>
            <div style="background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; margin-bottom: 20px;">
                <h2 style="margin-top: 0; margin-bottom: 15px;"><?php echo esc_html__('Token 管理', 'chatbudgie'); ?></h2>
                <div style="display: flex; align-items: center; gap: 20px;">
                    <div>
                        <p style="font-size: 16px; font-weight: 600; margin: 0;">
                            <?php echo esc_html__('剩余 Token:', 'chatbudgie'); ?> <span style="color: #667eea; font-size: 24px;"><?php echo esc_html(get_option('chatbudgie_tokens', 1000)); ?></span>
                        </p>
                        <p style="font-size: 12px; color: #666; margin: 5px 0 0;">
                            <?php echo esc_html__('用于调用 API 的 token 数量', 'chatbudgie'); ?>
                        </p>
                    </div>
                    <button type="button" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 6px; padding: 10px 20px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
                        <?php echo esc_html__('充值 Token', 'chatbudgie'); ?>
                    </button>
                </div>
            </div>
            <form method="post" action="options.php">
                <?php settings_fields('chatbudgie_settings'); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php echo esc_html__('聊天气泡图标', 'chatbudgie'); ?></th>
                        <td>
                            <?php $icon_type = get_option('chatbudgie_icon_type', 'default'); ?>
                            <label style="display: block; margin-bottom: 10px;">
                                <input type="radio" name="chatbudgie_icon_type" value="default" <?php checked($icon_type, 'default'); ?> />
                                <span style="margin-left: 8px;"><?php echo esc_html__('默认图标', 'chatbudgie'); ?></span>
                                <span style="margin-left: 10px; display: inline-block; width: 40px; height: 40px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 50%; vertical-align: middle; text-align: center; line-height: 40px;">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" style="vertical-align: middle;">
                                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                                    </svg>
                                </span>
                            </label>
                            <label style="display: block; margin-bottom: 10px;">
                                <input type="radio" name="chatbudgie_icon_type" value="robot" <?php checked($icon_type, 'robot'); ?> />
                                <span style="margin-left: 8px;"><?php echo esc_html__('机器人', 'chatbudgie'); ?></span>
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
                                <span style="margin-left: 8px;"><?php echo esc_html__('客服', 'chatbudgie'); ?></span>
                                <span style="margin-left: 10px; display: inline-block; width: 40px; height: 40px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 50%; vertical-align: middle; text-align: center; line-height: 40px;">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" style="vertical-align: middle;">
                                        <path d="M3 18v-6a9 9 0 0 1 18 0v6"></path>
                                        <path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"></path>
                                    </svg>
                                </span>
                            </label>
                            <label style="display: block; margin-bottom: 10px;">
                                <input type="radio" name="chatbudgie_icon_type" value="message" <?php checked($icon_type, 'message'); ?> />
                                <span style="margin-left: 8px;"><?php echo esc_html__('消息', 'chatbudgie'); ?></span>
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

                    // Token 充值功能
                    var rechargeButton = document.querySelector('button[type="button"]');
                    if (rechargeButton) {
                        rechargeButton.addEventListener('click', function() {
                            var amount = prompt('<?php echo esc_js(__('请输入要充值的 Token 数量:', 'chatbudgie')); ?>', '1000');
                            if (amount && !isNaN(amount) && amount > 0) {
                                var currentTokens = parseInt('<?php echo esc_js(get_option('chatbudgie_tokens', 1000)); ?>');
                                var newTokens = currentTokens + parseInt(amount);
                                
                                // 创建隐藏字段来存储新的 token 数量
                                var tokenField = document.getElementById('chatbudgie_tokens');
                                if (!tokenField) {
                                    tokenField = document.createElement('input');
                                    tokenField.type = 'hidden';
                                    tokenField.id = 'chatbudgie_tokens';
                                    tokenField.name = 'chatbudgie_tokens';
                                    document.querySelector('form').appendChild(tokenField);
                                }
                                tokenField.value = newTokens;
                                
                                // 显示成功消息
                                alert('<?php echo esc_js(__('充值成功！', 'chatbudgie')); ?>\n<?php echo esc_js(__('新的 Token 数量:', 'chatbudgie')); ?> ' + newTokens);
                                
                                // 更新显示
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

function ChatBudgie() {
    return ChatBudgie::get_instance();
}

ChatBudgie();
