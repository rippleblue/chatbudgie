<?php

/**
 * Template for the ChatBudgie frontend chat widget
 */

if (!defined('ABSPATH')) {
    exit;
}

$icon_type = get_option('chatbudgie_icon_type', 'default');
$custom_icon = get_option('chatbudgie_custom_icon', '');
$avatar_url = CHATBUDGIE_PLUGIN_URL . '/assets/images/budgie-avatar.png';
?>

<div class="chatbudgie-widget" id="chatbudgie-widget" role="dialog" aria-label="ChatBudgie chat window">
    <div class="chatbudgie-toggle" id="chatbudgie-toggle">
        <img src="<?php echo $avatar_url; ?>" alt="ChatBudgie avatar">
    </div>
    <!-- Header -->
    <header class="chatbudgie-header" id="chatbudgie-header">
        <div class="chatbudgie-header__brand">
            <img src="<?php echo $avatar_url; ?>" alt="ChatBudgie avatar" class="chatbudgie-header__avatar" aria-hidden="true">
            <h1 class="chatbudgie-header__title">ChatBudgie</h1>
        </div>
        <button class="chatbudgie-header__close" id="chatbudgie-close-btn" aria-label="Close chat">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                <path d="M6 6 L18 18 M18 6 L6 18" />
            </svg>
        </button>
    </header>

    <!-- Messages -->
    <main class="chatbudgie-messages" id="chatbudgie-messages">
        <div class="chatbudgie-msg chatbudgie-msg--user">
            <div class="chatbudgie-bubble chatbudgie-bubble--user">What services do you offer?</div>
        </div>

        <div class="chatbudgie-msg chatbudgie-msg--bot">
        <img src="<?php echo $avatar_url; ?>" alt="ChatBudgie avatar" class="chatbudgie-bot-avatar" aria-hidden="true" />
        <div class="chatbudgie-bubble chatbudgie-bubble--bot">We offer AI chatbot solutions that help you automate support and engage your visitors.</div>
</div>

<div class="chatbudgie-msg chatbudgie-msg--user">
    <div class="chatbudgie-bubble chatbudgie-bubble--user">Is my data safe?</div>
</div>

<!-- Error banner -->
<div class="chatbudgie-error-banner" id="chatbudgie-error-banner" role="alert">
    <div class="chatbudgie-error-banner__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="#E53935" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
            <line x1="12" y1="9" x2="12" y2="13" />
            <line x1="12" y1="17" x2="12.01" y2="17" />
        </svg>
    </div>
    <div class="chatbudgie-error-banner__content">
        <p class="chatbudgie-error-banner__title">Oops! Something went wrong.</p>
        <p class="chatbudgie-error-banner__text">I'm having a little trouble thinking right now. Please try again in a moment.</p>
    </div>
    <button class="chatbudgie-retry-btn" id="chatbudgie-retry-btn" type="button" aria-label="Try again">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#3F7CF5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="23 4 23 10 17 10" />
            <polyline points="1 20 1 14 7 14" />
            <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10" />
            <path d="M20.49 15a9 9 0 0 1-14.85 3.36L1 14" />
        </svg>
        <span>Try Again</span>
    </button>
</div>
</main>

<!-- Input -->
<form class="chatbudgie-input" id="chatbudgie-form" autocomplete="off">
    <input
        type="text"
        id="chatbudgie-input-field"
        class="chatbudgie-input__field"
        placeholder="Ask anything..."
        aria-label="Type your message" />
    <button type="submit" class="chatbudgie-input__send" id="chatbudgie-send-btn" aria-label="Send message">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#3F7CF5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M22 2 11 13" />
            <path d="M22 2 15 22 11 13 2 9 22 2z" />
        </svg>
    </button>
    <button type="button" class="chatbudgie-input__stop" id="chatbudgie-stop-btn" aria-label="Stop response" hidden>
        <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true">
            <rect x="7" y="7" width="10" height="10" rx="2" ry="2"></rect>
        </svg>
    </button>
</form>
</div>
