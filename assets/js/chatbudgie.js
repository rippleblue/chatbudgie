(function($) {
    'use strict';

    var conversationHistory = [];
    var isSending = false;
    var lastFailedMessage = '';
    var botAvatarHtml = '';
    var errorBannerTemplate = '';

    var $widget;
    var $toggle;
    var $closeBtn;
    var $messages;
    var $form;
    var $input;
    var $sendBtn;

    $(document).ready(function() {
        $widget = $('.chat-widget').first();

        if (!$widget.length) {
            return;
        }

        $toggle = $widget.find('.chatbudgie-toggle');
        $closeBtn = $widget.find('.chat-header__close');
        $messages = $widget.find('.chat-messages');
        $form = $widget.find('#chatForm');
        $input = $widget.find('#chatInputField');
        $sendBtn = $widget.find('.chat-input__send');

        botAvatarHtml = getBotAvatarHtml();
        errorBannerTemplate = getErrorBannerTemplate();

        initializeWidget();
        bindEvents();
    });

    function initializeWidget() {
        $widget.removeClass('is-open');

        $input.attr('placeholder', chatbudgie_params.strings.placeholder);
        clearMessages();
        hideErrorBanner();
        addBotMessage('How can I help you today?');
    }

    function bindEvents() {
        $toggle.on('click', openChat);
        $closeBtn.on('click', closeChat);

        $(document).on('mousedown', handleDocumentPointerDown);

        $form.on('submit', function(event) {
            event.preventDefault();
            sendMessage();
        });

        $messages.on('click', '.retry-btn', function() {
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

    function openChat() {
        $widget.addClass('is-open');
        window.setTimeout(function() {
            $input.trigger('focus');
            scrollToBottom();
        }, 120);
    }

    function closeChat() {
        $widget.removeClass('is-open');
    }

    function handleDocumentPointerDown(event) {
        if (!$widget.hasClass('is-open')) {
            return;
        }

        if ($(event.target).closest('.chat-widget').length) {
            return;
        }

        closeChat();
    }

    function sendMessage(messageOverride, options) {
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
        setSendingState(true);

        var $loadingMessage = addLoadingMessage();
        var xhr = new XMLHttpRequest();
        var processedLength = 0;
        var accumulatedReply = '';
        var streamError = '';

        xhr.open('POST', chatbudgie_params.sse_url, true);

        xhr.onreadystatechange = function() {
            if (xhr.readyState !== XMLHttpRequest.LOADING && xhr.readyState !== XMLHttpRequest.DONE) {
                return;
            }

            var chunk = xhr.responseText.substring(processedLength);
            processedLength = xhr.responseText.length;

            if (chunk) {
                var streamResult = consumeSSEChunk(chunk);

                if (streamResult.error) {
                    streamError = streamResult.error;
                }

                if (streamResult.content) {
                    accumulatedReply += streamResult.content;
                    updateAssistantMessage($loadingMessage, accumulatedReply);
                }
            }

            if (xhr.readyState === XMLHttpRequest.DONE) {
                finalizeRequest(xhr, $loadingMessage, accumulatedReply, streamError, message);
            }
        };

        xhr.onerror = function() {
            handleRequestFailure($loadingMessage, chatbudgie_params.strings.error);
        };

        xhr.send(buildFormData(message));
    }

    function finalizeRequest(xhr, $loadingMessage, accumulatedReply, streamError) {
        if (xhr.status === 200 && !streamError && accumulatedReply) {
            conversationHistory.push({ role: 'assistant', content: accumulatedReply });
            lastFailedMessage = '';
            setSendingState(false);
            scrollToBottom();
            return;
        }

        var message = streamError || chatbudgie_params.strings.error;
        handleRequestFailure($loadingMessage, message);
    }

    function handleRequestFailure($loadingMessage, message) {
        if (conversationHistory.length && conversationHistory[conversationHistory.length - 1].role === 'user') {
            conversationHistory.pop();
        }

        if ($loadingMessage && $loadingMessage.length) {
            $loadingMessage.remove();
        }

        showErrorBanner(message);
        setSendingState(false);
    }

    function setSendingState(state) {
        isSending = state;
        $sendBtn.prop('disabled', state);
        $input.prop('disabled', state);
    }

    function buildFormData(message) {
        var formData = new FormData();
        formData.append('action', 'chatbudgie_send_message_sse');
        formData.append('nonce', chatbudgie_params.nonce);
        formData.append('message', message);
        formData.append('conversation_history', JSON.stringify(conversationHistory));

        return formData;
    }

    function consumeSSEChunk(chunk) {
        var result = {
            content: '',
            error: ''
        };
        var lines = chunk.split(/\r?\n/);

        for (var i = 0; i < lines.length; i++) {
            var line = $.trim(lines[i]);

            if (!line || line.indexOf('data:') !== 0) {
                continue;
            }

            var payload = $.trim(line.substring(5));

            if (!payload) {
                continue;
            }

            if (payload === '[DONE]') {
                continue;
            }

            try {
                var parsed = JSON.parse(payload);

                if (parsed && typeof parsed.error === 'string') {
                    result.error = parsed.error;
                    continue;
                }

                if (parsed && typeof parsed.content === 'string') {
                    result.content += parsed.content;
                    continue;
                }

                if (parsed && typeof parsed.delta === 'string') {
                    result.content += parsed.delta;
                    continue;
                }

                if (parsed && parsed.choices && parsed.choices[0] && parsed.choices[0].delta && typeof parsed.choices[0].delta.content === 'string') {
                    result.content += parsed.choices[0].delta.content;
                    continue;
                }
            } catch (error) {
                result.content += payload;
            }
        }

        return result;
    }

    function clearMessages() {
        $messages.empty();
    }

    function addUserMessage(content) {
        var $message = $('<div class="msg msg--user"></div>');
        var $bubble = $('<div class="bubble bubble--user"></div>').text(content);

        $message.append($bubble);
        $messages.append($message);
        scrollToBottom();

        return $message;
    }

    function addBotMessage(content) {
        var $message = $('<div class="msg msg--bot"></div>');
        var $avatar = $(botAvatarHtml);
        var $bubble = $('<div class="bubble bubble--bot"></div>').text(content);

        $message.append($avatar, $bubble);
        $messages.append($message);
        scrollToBottom();

        return $message;
    }

    function addLoadingMessage() {
        var $message = $('<div class="msg msg--bot"></div>');
        var $avatar = $(botAvatarHtml);
        var $bubble = $('<div class="bubble bubble--bot bubble--loading"></div>');
        var $indicator = $('<div class="typing-indicator"><span></span><span></span><span></span></div>');

        $bubble.append($indicator);
        $message.append($avatar, $bubble);
        $messages.append($message);
        scrollToBottom();

        return $message;
    }

    function updateAssistantMessage($message, content) {
        if (!$message || !$message.length) {
            return;
        }

        var $bubble = $message.find('.bubble');
        $bubble.removeClass('bubble--loading').text(content);
        scrollToBottom();
    }

    function getBotAvatarHtml() {
        var $avatar = $widget.find('.bot-avatar').first().clone();

        if (!$avatar.length) {
            return '<div class="bot-avatar" aria-hidden="true"></div>';
        }

        return $('<div>').append($avatar).html();
    }

    function getErrorBannerTemplate() {
        var $banner = $widget.find('.error-banner').first().clone();

        if (!$banner.length) {
            return '';
        }

        $banner.attr('hidden', true);

        return $('<div>').append($banner).html();
    }

    function hideErrorBanner() {
        $messages.find('.error-banner').remove();
    }

    function showErrorBanner(message) {
        hideErrorBanner();

        if (!errorBannerTemplate) {
            return;
        }

        var $banner = $(errorBannerTemplate);
        $banner.find('.error-banner__text').text(message);
        $banner.removeAttr('hidden');
        $messages.append($banner);
        scrollToBottom();
    }

    function scrollToBottom() {
        if ($messages.length) {
            $messages.scrollTop($messages[0].scrollHeight);
        }
    }

})(jQuery);
