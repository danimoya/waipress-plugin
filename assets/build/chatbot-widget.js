/**
 * WAIpress Chatbot Widget
 * Public-facing floating chat widget for customer support.
 */
(function() {
  if (!window.waipressChatbot) return;

  const config = window.waipressChatbot;
  let sessionId = null;
  let visitorId = localStorage.getItem('waipress_visitor_id');
  if (!visitorId) {
    visitorId = 'v_' + Math.random().toString(36).substr(2, 12);
    localStorage.setItem('waipress_visitor_id', visitorId);
  }

  // Create widget HTML
  const widget = document.createElement('div');
  widget.id = 'waipress-chatbot-widget';
  widget.innerHTML = `
    <button id="wai-chat-toggle" aria-label="Open chat" style="
      position:fixed;bottom:20px;right:20px;width:56px;height:56px;border-radius:28px;
      background:#2271b1;color:#fff;border:none;cursor:pointer;font-size:24px;
      box-shadow:0 4px 12px rgba(0,0,0,0.15);z-index:99999;
      display:flex;align-items:center;justify-content:center;
      transition:transform 0.2s;
    ">&#128172;</button>
    <div id="wai-chat-panel" style="
      display:none;position:fixed;bottom:88px;right:20px;width:380px;height:500px;
      background:#fff;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,0.2);
      z-index:99999;overflow:hidden;flex-direction:column;
    ">
      <div style="background:#2271b1;color:#fff;padding:16px;display:flex;justify-content:space-between;align-items:center;">
        <div>
          <div style="font-weight:600;font-size:15px;">${config.siteTitle || 'Chat'}</div>
          <div style="font-size:12px;opacity:0.8;">We typically reply instantly</div>
        </div>
        <button id="wai-chat-close" style="background:none;border:none;color:#fff;font-size:20px;cursor:pointer;">&#10005;</button>
      </div>
      <div id="wai-chat-messages" style="flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:8px;"></div>
      <div style="padding:12px;border-top:1px solid #e2e4e7;">
        <div style="display:flex;gap:8px;">
          <input id="wai-chat-input" type="text" placeholder="Type a message..."
            style="flex:1;padding:8px 12px;border:1px solid #c3c4c7;border-radius:20px;outline:none;font-size:14px;" />
          <button id="wai-chat-send" style="
            background:#2271b1;color:#fff;border:none;border-radius:20px;padding:8px 16px;cursor:pointer;font-size:14px;
          ">Send</button>
        </div>
      </div>
    </div>
  `;

  document.body.appendChild(widget);

  const toggle = document.getElementById('wai-chat-toggle');
  const panel = document.getElementById('wai-chat-panel');
  const closeBtn = document.getElementById('wai-chat-close');
  const messagesEl = document.getElementById('wai-chat-messages');
  const input = document.getElementById('wai-chat-input');
  const sendBtn = document.getElementById('wai-chat-send');

  let isOpen = false;

  toggle.addEventListener('click', async () => {
    isOpen = !isOpen;
    panel.style.display = isOpen ? 'flex' : 'none';
    toggle.innerHTML = isOpen ? '&#10005;' : '&#128172;';

    if (isOpen && !sessionId) {
      await startSession();
    }
    if (isOpen) input.focus();
  });

  closeBtn.addEventListener('click', () => {
    isOpen = false;
    panel.style.display = 'none';
    toggle.innerHTML = '&#128172;';
  });

  async function startSession() {
    try {
      const res = await fetch(config.restUrl + 'start', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ visitor_id: visitorId }),
      });
      const data = await res.json();
      sessionId = data.session_id;

      if (data.welcome_message) {
        addMessage('assistant', data.welcome_message);
      }
    } catch (err) {
      addMessage('system', 'Unable to connect. Please try again later.');
    }
  }

  function addMessage(role, content) {
    const msg = document.createElement('div');
    msg.style.cssText = role === 'user'
      ? 'align-self:flex-end;background:#2271b1;color:#fff;padding:8px 14px;border-radius:16px 16px 4px 16px;max-width:80%;font-size:14px;line-height:1.4;'
      : role === 'assistant'
      ? 'align-self:flex-start;background:#f0f0f1;color:#1d2327;padding:8px 14px;border-radius:16px 16px 16px 4px;max-width:80%;font-size:14px;line-height:1.4;'
      : 'align-self:center;color:#787c82;font-size:12px;';
    msg.textContent = content;
    messagesEl.appendChild(msg);
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  async function sendMessage() {
    const text = input.value.trim();
    if (!text || !sessionId) return;

    addMessage('user', text);
    input.value = '';
    sendBtn.disabled = true;

    try {
      const res = await fetch(config.restUrl + sessionId + '/message', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ content: text }),
      });
      const data = await res.json();
      if (data.content) {
        addMessage('assistant', data.content);
      }
    } catch (err) {
      addMessage('system', 'Failed to send. Please try again.');
    }

    sendBtn.disabled = false;
    input.focus();
  }

  sendBtn.addEventListener('click', sendMessage);
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') sendMessage();
  });
})();
