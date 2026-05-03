/**
 * ChatBudgie frontend module.
 * Handles user interaction, UI updates, and streaming chat communication.
 */
(function($) {
    'use strict';

    var conversationHistory = [];
    var isSending = false;
    var lastFailedMessage = '';
    var botAvatarHtml = '';
    var errorBannerTemplate = '';
    var STORAGE_KEY = 'chatbudgie_history';
    var currentRequestController = null;
    var $widget;
    var $toggle;
    var $closeBtn;
    var $messages;
    var $form;
    var $input;
    var $sendBtn;
    var $stopBtn;

    /**
     * Initializes the chat widget components once the DOM is ready.
     */
    $(document).ready(function() {
        $widget = $('#chatbudgie-widget').first();

        if (!$widget.length) {
            return;
        }

        $toggle = $('#chatbudgie-toggle');
        $closeBtn = $('#chatbudgie-close-btn');
        $messages = $('#chatbudgie-messages');
        $form = $('#chatbudgie-form');
        $input = $('#chatbudgie-input-field');
        $sendBtn = $('#chatbudgie-send-btn');
        $stopBtn = $('#chatbudgie-stop-btn');

        botAvatarHtml = getBotAvatarHtml();
        errorBannerTemplate = getErrorBannerTemplate();

        initializeWidget();
        bindEvents();
    });

    /**
     * Resets the widget to its initial state, clearing messages and adding a welcome message.
     */
    function initializeWidget() {
        $widget.removeClass('is-open');

        $input.attr('placeholder', chatbudgie_params.strings.placeholder);
        clearMessages();
        hideErrorBanner();
        
        loadHistory();
        
        if (conversationHistory.length === 0) {
            addBotMessage(chatbudgie_params.strings.welcome);
        } else {
            renderHistory();
        }
    }

    /**
     * Loads conversation history from localStorage.
     * Only keeps history if it was updated within the last hour.
     */
    function loadHistory() {
        try {
            var stored = localStorage.getItem(STORAGE_KEY);
            if (!stored) {
                return;
            }

            var data = JSON.parse(stored);
            var oneHour = 3600 * 1000;
            var now = Date.now();

            // Handle new object format with expiration
            if (data && data.timestamp && Array.isArray(data.messages)) {
                if (now - data.timestamp < oneHour) {
                    conversationHistory = data.messages;
                    return;
                }
            }
            localStorage.removeItem(STORAGE_KEY);
            conversationHistory = [];
        } catch (e) {
            console.error('ChatBudgie: Failed to load history', e);
            conversationHistory = [];
        }
    }

    /**
     * Saves conversation history to localStorage.
     * Restricts history to the latest 20 messages and adds a timestamp.
     */
    function saveHistory() {
        try {
            // Only keep the latest 20 messages
            if (conversationHistory.length > 20) {
                conversationHistory = conversationHistory.slice(-20);
            }

            var data = {
                timestamp: Date.now(),
                messages: conversationHistory
            };
            localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
        } catch (e) {
            console.error('ChatBudgie: Failed to save history', e);
        }
    }

    /**
     * Renders the loaded conversation history.
     */
    function renderHistory() {
        conversationHistory.forEach(function(msg) {
            if (msg.role === 'user') {
                addUserMessage(msg.content, true);
            } else if (msg.role === 'assistant') {
                addBotMessage(msg.content, true);
            }
        });
        scrollToBottom();
    }

    /**
     * Binds click and submit event listeners for widget interactions.
     */
    function bindEvents() {
        $toggle.on('click', openChat);
        $closeBtn.on('click', closeChat);

        $(document).on('mousedown', handleDocumentPointerDown);

        $form.on('submit', function(event) {
            event.preventDefault();
            sendMessage();
        });

        $stopBtn.on('click', stopCurrentResponse);

        $messages.on('click', '.chatbudgie-retry-btn', function() {
            if (!lastFailedMessage || isSending) {
                return;
            }

            hideErrorBanner();
            sendMessage(lastFailedMessage, {
                skipUserBubble: true,
                skipInputReset: true
            });
        });
    }

    /**
     * Opens the chat widget and focuses the input field.
     */
    function openChat() {
        $widget.addClass('is-open');
        window.setTimeout(function() {
            $input.trigger('focus');
            scrollToBottom();
        }, 120);
    }

    /**
     * Closes the chat widget.
     */
    function closeChat() {
        $widget.removeClass('is-open');
    }

    /**
     * Handles closing the widget when clicking outside of it.
     */
    function handleDocumentPointerDown(event) {
        if (!$widget.hasClass('is-open')) {
            return;
        }

        if ($(event.target).closest('.chatbudgie-widget').length) {
            return;
        }

        closeChat();
    }

    /**
     * Sends a user message to the server and handles the streaming assistant response.
     * Uses native fetch and ReadableStream for robust SSE support without external dependencies.
     * 
     * @param {string|null} messageOverride - Optional message string to send instead of input value.
     * @param {Object} options - Configuration to skip UI updates.
     */
    async function sendMessage(messageOverride, options) {
        var opts = $.extend({
            skipUserBubble: false,
            skipInputReset: false
        }, options);
        var message = $.trim(typeof messageOverride === 'string' ? messageOverride : $input.val());

        if (!message || isSending) {
            return;
        }

        hideErrorBanner();

        if (!opts.skipUserBubble) {
            addUserMessage(message);
        }

        if (!opts.skipInputReset) {
            $input.val('');
        }

        lastFailedMessage = message;
        conversationHistory.push({ role: 'user', content: message });
        saveHistory();
        setSendingState(true);

        var $loadingMessage = addLoadingMessage();
        var accumulatedReply = '';
        var streamError = '';
        currentRequestController = new AbortController();

        try {
            const response = await fetch(chatbudgie_params.sse_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams(buildFormData(message)).toString(),
                signal: currentRequestController.signal
            });

            if (!response.ok) {
                var errorText = await response.text();
                throw new Error(errorText || chatbudgie_params.strings.error);
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            var buffer = '';

            while (true) {
                const { done, value } = await reader.read();
                if (done) {
                    buffer += decoder.decode();
                    accumulatedReply += parseSseEvent(splitSseEvents(buffer));
                    break;
                }

                if (!value) {
                    continue;
                }

                buffer += decoder.decode(value, { stream: true });
                var events = splitSseEvents(buffer);
                buffer = events.pop();

                var eventData = parseSseEvent(events);
                if (!eventData || eventData === '[DONE]') continue;
                accumulatedReply += eventData;
                updateAssistantMessage($loadingMessage, accumulatedReply);
            }

            finalizeRequest(response, $loadingMessage, accumulatedReply, streamError);
        } catch (error) {
            if (error && error.name === 'AbortError') {
                handleRequestAbort($loadingMessage, accumulatedReply);
                return;
            }

            handleRequestFailure($loadingMessage, error.message || chatbudgie_params.strings.error);
        } finally {
            currentRequestController = null;
        }

        /**
         * Normalizes SSE line endings and splits the stream into event blocks.
         *
         * @param {string} sseText - Raw SSE stream text buffered from the response.
         * @returns {string[]} SSE event blocks split on blank-line separators.
         */
        function splitSseEvents(sseText) {
            return sseText.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n\n');
        }

        /**
         * Parses one or more complete SSE event blocks and combines their data payloads.
         *
         * @param {string[]} eventBlocks - SSE event blocks already split on blank lines.
         * @returns {string} Concatenated event data with per-event data lines joined by newlines.
         */
        function parseSseEvent(eventBlocks) {
            var eventData = '';
            if (!eventBlocks) return eventData;

            for (var i = 0; i < eventBlocks.length; i++) {
                if (!eventBlocks[i]) continue;

                var lines = eventBlocks[i].split('\n');
                var dataLines = [];

                for (var j = 0; j < lines.length; j++) {
                    var line = lines[j];
                    if (!line || line.indexOf('data:') !== 0) continue;

                    dataLines.push(line.substring(5));
                }
                if (!dataLines.length) continue;

                eventData += dataLines.join('\n');
            }

            return eventData;
        }
    }

    /**
     * Finalizes the message request by updating history and resetting UI state.
     * 
     * @param {Response} response - The fetch Response object.
     * @param {jQuery} $loadingMessage - The placeholder bot message element.
     * @param {string} accumulatedReply - The full bot response text.
     * @param {string} streamError - Any error encountered during streaming.
     */
    function finalizeRequest(response, $loadingMessage, accumulatedReply, streamError) {
        if (response.status === 200 && !streamError && accumulatedReply) {
            conversationHistory.push({ role: 'assistant', content: accumulatedReply });
            saveHistory();
            lastFailedMessage = '';
            setSendingState(false);
            scrollToBottom();
            return;
        }

        var message = streamError || chatbudgie_params.strings.error;
        handleRequestFailure($loadingMessage, message);
    }

    /**
     * Handles a user-cancelled request, preserving any partial assistant response that was already streamed.
     *
     * @param {jQuery} $loadingMessage - The bot message element being populated.
     * @param {string} accumulatedReply - The assistant text received before cancellation.
     */
    function handleRequestAbort($loadingMessage, accumulatedReply) {
        if (accumulatedReply) {
            updateAssistantMessage($loadingMessage, accumulatedReply);
            conversationHistory.push({ role: 'assistant', content: accumulatedReply });
            saveHistory();
            lastFailedMessage = '';
        }
        if ($loadingMessage && $loadingMessage.length) {
            $loadingMessage.remove();
        }

        setSendingState(false);
        scrollToBottom();
    }

    /**
     * Handles request failures by cleaning up state and showing an error banner.
     * 
     * @param {jQuery} $loadingMessage - The bot message element to remove.
     * @param {string} message - The error message to display.
     */
    function handleRequestFailure($loadingMessage, message) {
        if (conversationHistory.length && conversationHistory[conversationHistory.length - 1].role === 'user') {
            conversationHistory.pop();
            saveHistory();
        }

        if ($loadingMessage && $loadingMessage.length) {
            $loadingMessage.remove();
        }

        showErrorBanner(message);
        setSendingState(false);
    }

    /**
     * Updates UI elements to reflect whether a message is currently being sent.
     * 
     * @param {boolean} state - True if sending, false otherwise.
     */
    function setSendingState(state) {
        isSending = state;
        $input.prop('disabled', state);
        updateSendButtonState(state);
    }

    /**
     * Updates the chat action button between send and stop states.
     *
     * @param {boolean} isWaiting - True while an assistant response is streaming.
     */
    function updateSendButtonState(isWaiting) {
        $sendBtn.prop('hidden', isWaiting);
        $stopBtn.prop('hidden', !isWaiting);
    }

    /**
     * Cancels the active SSE request if one is in progress.
     */
    function stopCurrentResponse() {
        if (!currentRequestController) {
            return;
        }

        currentRequestController.abort();
    }

    /**
     * Constructs FormData for the AJAX request including nonce and history.
     * 
     * @param {string} message - The user's message.
     * @returns {Object}
     */
    function buildFormData(message) {
        return {
            action: 'chatbudgie_send_message_sse',
            nonce: chatbudgie_params.nonce,
            message: message,
            conversation_history: JSON.stringify(conversationHistory)
        };
    }

    /**
     * Clears all message bubbles from the chat container.
     */
    function clearMessages() {
        $messages.empty();
    }

    /**
     * Appends a user message bubble to the chat window.
     * 
     * @param {string} content - Message text.
     * @param {boolean} skipScroll - Whether to skip automatic scrolling.
     * @returns {jQuery} The created message element.
     */
    function addUserMessage(content, skipScroll) {
        var $message = $('<div class="chatbudgie-msg chatbudgie-msg--user"></div>');
        var $bubble = $('<div class="chatbudgie-bubble chatbudgie-bubble--user"></div>').text(content);

        $message.append($bubble);
        $messages.append($message);
        
        if (!skipScroll) {
            scrollToBottom();
        }

        return $message;
    }

    /**
     * Appends a bot message bubble with an avatar to the chat window.
     * 
     * @param {string} content - Message text.
     * @param {boolean} skipScroll - Whether to skip automatic scrolling.
     * @returns {jQuery} The created message element.
     */
    function addBotMessage(content, skipScroll) {
        var $message = $('<div class="chatbudgie-msg chatbudgie-msg--bot"></div>');
        var $avatar = $(botAvatarHtml);
        var $bubble = $('<div class="chatbudgie-bubble chatbudgie-bubble--bot"></div>');

        if (window.marked && typeof marked.parse === 'function') {
            $bubble.html(marked.parse(content));
        } else {
            $bubble.text(content);
        }

        $message.append($avatar, $bubble);
        $messages.append($message);
        
        if (!skipScroll) {
            scrollToBottom();
        }

        return $message;
    }

    /**
     * Appends a bot message bubble with a typing indicator.
     * 
     * @returns {jQuery} The created message element.
     */
    function addLoadingMessage() {
        var $message = $('<div class="chatbudgie-msg chatbudgie-msg--bot"></div>');
        var $avatar = $(botAvatarHtml);
        var $bubble = $('<div class="chatbudgie-bubble chatbudgie-bubble--bot chatbudgie-bubble--loading"></div>');
        var $indicator = $('<div class="chatbudgie-typing-indicator"><span></span><span></span><span></span></div>');

        $bubble.append($indicator);
        $message.append($avatar, $bubble);
        $messages.append($message);
        scrollToBottom();

        return $message;
    }

    /**
     * Replaces the loading indicator with actual assistant content.
     * 
     * @param {jQuery} $message - The bot message element to update.
     * @param {string} content - The updated message content.
     */
    function updateAssistantMessage($message, content) {
        if (!$message || !$message.length) {
            return;
        }

        var $bubble = $message.find('.chatbudgie-bubble');
        $bubble.removeClass('chatbudgie-bubble--loading');
        
        if (window.marked && typeof marked.parse === 'function') {
            $bubble.html(marked.parse(content));
        } else {
            $bubble.text(content);
        }
        
        scrollToBottom();
    }

    /**
     * Clones the bot avatar HTML from the DOM template.
     * 
     * @returns {string} The HTML string of the bot avatar.
     */
    function getBotAvatarHtml() {
        var $avatar = $widget.find('.chatbudgie-bot-avatar').first().clone();

        if (!$avatar.length) {
            return '<div class="chatbudgie-bot-avatar" aria-hidden="true"></div>';
        }

        return $('<div>').append($avatar).html();
    }

    /**
     * Clones the error banner HTML from the DOM template.
     * 
     * @returns {string} The HTML string of the error banner.
     */
    function getErrorBannerTemplate() {
        var $banner = $widget.find('.chatbudgie-error-banner').first().clone();

        if (!$banner.length) {
            return '';
        }

        $banner.attr('hidden', true);

        return $('<div>').append($banner).html();
    }

    /**
     * Removes any existing error banners from the chat window.
     */
    function hideErrorBanner() {
        $messages.find('.chatbudgie-error-banner').remove();
    }

    /**
     * Displays an error message in an error banner.
     * 
     * @param {string} message - The error message text.
     */
    function showErrorBanner(message) {
        hideErrorBanner();

        if (!errorBannerTemplate) {
            return;
        }

        var $banner = $(errorBannerTemplate);
        $banner.find('.chatbudgie-error-banner__text').text(message);
        $banner.removeAttr('hidden');
        $messages.append($banner);
        scrollToBottom();
    }

    /**
     * Scrolls the chat window to the bottom.
     */
    function scrollToBottom() {
        if ($messages.length) {
            $messages.scrollTop($messages[0].scrollHeight);
        }
    }

})(jQuery);
