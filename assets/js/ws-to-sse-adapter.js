/**
 * WAIpress WebSocket-to-SSE/REST Adapter
 *
 * Intercepts WebSocket connections from compiled JS bundles
 * and redirects them to SSE (chatbot) or REST polling (messaging).
 * Loaded before the bundle scripts so the override is in place.
 */
(function () {
  'use strict';

  var OriginalWebSocket = window.WebSocket;

  /**
   * SSE-based adapter for chatbot streaming.
   */
  function ChatbotSSEAdapter(url) {
    var self = this;
    var parsedUrl = new URL(url);
    var sessionId = parsedUrl.searchParams.get('session');

    this.readyState = 0; // CONNECTING
    this.url = url;
    this.onopen = null;
    this.onmessage = null;
    this.onclose = null;
    this.onerror = null;
    this._closed = false;

    // Simulate connection open
    setTimeout(function () {
      if (self._closed) return;
      self.readyState = 1; // OPEN
      if (self.onopen) self.onopen({ type: 'open' });
      if (self.onmessage) {
        self.onmessage({
          data: JSON.stringify({ event: 'connected', data: { sessionId: sessionId } }),
        });
      }
    }, 50);
  }

  ChatbotSSEAdapter.prototype.send = function (raw) {
    if (this._closed || this.readyState !== 1) return;

    var self = this;
    var msg;
    try {
      msg = JSON.parse(raw);
    } catch (e) {
      return;
    }

    if (msg.type !== 'message' || !msg.content) return;

    var parsedUrl = new URL(this.url);
    var sessionId = parsedUrl.searchParams.get('session');

    // Build form data for SSE endpoint
    var formData = new FormData();
    formData.append('action', 'waipress_chatbot_stream');
    formData.append('session_id', sessionId);
    formData.append('content', msg.content);

    var sseUrl =
      (window.waipressChatbot && window.waipressChatbot.sseUrl) ||
      (window.waipressConfig && window.waipressConfig.sseUrl) ||
      '/wp-admin/admin-ajax.php';

    fetch(sseUrl, { method: 'POST', body: formData })
      .then(function (response) {
        if (!response.ok) throw new Error('SSE request failed');

        var reader = response.body.getReader();
        var decoder = new TextDecoder();
        var buffer = '';

        function processChunk() {
          return reader.read().then(function (result) {
            if (result.done) {
              if (self.onmessage) {
                self.onmessage({
                  data: JSON.stringify({ event: 'chatbot_complete', data: { sessionId: sessionId } }),
                });
              }
              return;
            }

            buffer += decoder.decode(result.value, { stream: true });
            var lines = buffer.split('\n');
            buffer = lines.pop() || '';

            for (var i = 0; i < lines.length; i++) {
              var line = lines[i].trim();
              if (line.indexOf('data: ') !== 0) continue;

              var json;
              try {
                json = JSON.parse(line.substring(6));
              } catch (e) {
                continue;
              }

              if (json.text && self.onmessage) {
                self.onmessage({
                  data: JSON.stringify({
                    event: 'chatbot_chunk',
                    data: { text: json.text, sessionId: sessionId },
                  }),
                });
              }

              if (json.done && self.onmessage) {
                self.onmessage({
                  data: JSON.stringify({
                    event: 'chatbot_complete',
                    data: { sessionId: sessionId },
                  }),
                });
              }
            }

            return processChunk();
          });
        }

        return processChunk();
      })
      .catch(function (err) {
        if (self.onerror) self.onerror(err);
      });
  };

  ChatbotSSEAdapter.prototype.close = function () {
    this._closed = true;
    this.readyState = 3; // CLOSED
    if (this.onclose) this.onclose({ code: 1000, reason: 'Normal closure' });
  };

  /**
   * Polling-based adapter for messaging/dashboard WebSocket channels.
   */
  function MessagingPollAdapter(url) {
    var self = this;
    this.readyState = 0;
    this.url = url;
    this.onopen = null;
    this.onmessage = null;
    this.onclose = null;
    this.onerror = null;
    this._closed = false;
    this._pollTimer = null;
    this._lastPoll = new Date().toISOString();

    var restBase =
      (window.waipressConfig && window.waipressConfig.restUrl) || '/wp-json/waipress/v1/';
    var nonce = (window.waipressConfig && window.waipressConfig.nonce) || '';

    setTimeout(function () {
      if (self._closed) return;
      self.readyState = 1;
      if (self.onopen) self.onopen({ type: 'open' });

      // Start polling every 8 seconds
      self._pollTimer = setInterval(function () {
        if (self._closed) return;

        var pollUrl = restBase + 'messaging/updates?since=' + encodeURIComponent(self._lastPoll);
        fetch(pollUrl, {
          headers: { 'X-WP-Nonce': nonce },
        })
          .then(function (r) {
            return r.json();
          })
          .then(function (data) {
            if (data.server_time) self._lastPoll = data.server_time;
            if (data.conversations && data.conversations.length && self.onmessage) {
              self.onmessage({
                data: JSON.stringify({
                  event: 'conversations_updated',
                  data: data.conversations,
                }),
              });
            }
          })
          .catch(function () {
            /* silent polling failure */
          });
      }, 8000);
    }, 50);
  }

  MessagingPollAdapter.prototype.send = function () {
    // Messaging adapter doesn't need to send; typing indicators are dropped
  };

  MessagingPollAdapter.prototype.close = function () {
    this._closed = true;
    this.readyState = 3;
    if (this._pollTimer) clearInterval(this._pollTimer);
    if (this.onclose) this.onclose({ code: 1000, reason: 'Normal closure' });
  };

  /**
   * Override WebSocket constructor for WAIpress URLs.
   */
  window.WebSocket = function (url, protocols) {
    var urlStr = typeof url === 'string' ? url : url.toString();

    // Chatbot channel -> SSE adapter
    if (urlStr.indexOf('/chatbot') !== -1 && urlStr.indexOf('session=') !== -1) {
      return new ChatbotSSEAdapter(urlStr);
    }

    // Messaging/dashboard channel -> polling adapter
    if (
      urlStr.indexOf('waipress') !== -1 &&
      (urlStr.indexOf('/messaging') !== -1 || urlStr.indexOf('/dashboard') !== -1)
    ) {
      return new MessagingPollAdapter(urlStr);
    }

    // Everything else -> real WebSocket
    if (protocols) {
      return new OriginalWebSocket(url, protocols);
    }
    return new OriginalWebSocket(url);
  };

  // Preserve static properties
  window.WebSocket.CONNECTING = 0;
  window.WebSocket.OPEN = 1;
  window.WebSocket.CLOSING = 2;
  window.WebSocket.CLOSED = 3;
  window.WebSocket.prototype = OriginalWebSocket.prototype;
})();
