(function($) {
    'use strict';

    var conversationHistory = [];
    var $widget, $container, $toggle, $messages, $input, $sendBtn;

    $(document).ready(function() {
        $widget = $('#chatbudgie-widget');
        $container = $widget.find('.chatbudgie-container');
        $toggle = $widget.find('.chatbudgie-toggle');
        $messages = $widget.find('.chatbudgie-messages');
        $input = $widget.find('.chatbudgie-input');
        $sendBtn = $widget.find('.chatbudgie-send');

        $toggle.on('click', toggleChat);
        $widget.find('.chatbudgie-close').on('click', closeChat);
        $sendBtn.on('click', sendMessage);
        $input.on('keypress', function(e) {
            if (e.which === 13 && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        addInitialMessage();
    });

    function toggleChat() {
        $container.toggleClass('active');
        if ($container.hasClass('active')) {
            $input.focus();
        }
    }

    function closeChat() {
        $container.removeClass('active');
    }

    function addInitialMessage() {
        var initialHtml = '<div class="chatbudgie-initial-message">' +
            '<h4>Hello! I\'m ChatBudgie</h4>' +
            '<p>How can I help you today?</p>' +
            '</div>';
        $messages.html(initialHtml);
    }

    function sendMessage() {
        var message = $.trim($input.val());
        if (!message) return;

        addMessage(message, 'user');
        $input.val('');
        $sendBtn.prop('disabled', true).text(chatbudgie_params.strings.sending);

        conversationHistory.push({ role: 'user', content: message });

        var $loadingMsg = addMessage('', 'loading');

        $.ajax({
            url: chatbudgie_params.ajax_url,
            type: 'POST',
            data: {
                action: 'chatbudgie_send_message',
                nonce: chatbudgie_params.nonce,
                message: message,
                conversation_history: conversationHistory
            },
            success: function(response) {
                $loadingMsg.remove();
                if (response.success) {
                    var reply = response.data.reply;
                    conversationHistory.push({ role: 'assistant', content: reply });
                    addMessage(reply, 'assistant');
                } else {
                    conversationHistory.pop();
                    addMessage(response.data.message || chatbudgie_params.strings.error, 'error');
                }
            },
            error: function() {
                $loadingMsg.remove();
                conversationHistory.pop();
                addMessage(chatbudgie_params.strings.error, 'error');
            },
            complete: function() {
                $sendBtn.prop('disabled', false).text(chatbudgie_params.strings.sending.replace('...', ''));
                scrollToBottom();
            }
        });
    }

    function addMessage(content, type) {
        var $msg = $('<div class="chatbudgie-message ' + type + '"></div>');

        if (type === 'loading') {
            $msg.html('<div class="typing-indicator"><span></span><span></span><span></span></div>');
        } else {
            $msg.text(content);
        }

        var $initial = $messages.find('.chatbudgie-initial-message');
        if ($initial.length) {
            $initial.remove();
        }

        $messages.append($msg);
        scrollToBottom();

        return $msg;
    }

    function scrollToBottom() {
        $messages.scrollTop($messages[0].scrollHeight);
    }

})(jQuery);
