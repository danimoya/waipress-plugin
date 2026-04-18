/**
 * WAIpress Messaging Inbox SPA
 * React-based unified messaging inbox for WhatsApp, Telegram, Instagram.
 */
(function() {
  const { createElement: h, useState, useEffect, useCallback } = wp.element;

  const MessagingInbox = () => {
    const [conversations, setConversations] = useState([]);
    const [selected, setSelected] = useState(null);
    const [messages, setMessages] = useState([]);
    const [reply, setReply] = useState('');
    const [loading, setLoading] = useState(true);

    useEffect(() => {
      loadConversations();
    }, []);

    const loadConversations = async () => {
      setLoading(true);
      try {
        const data = await wp.apiFetch({ path: '/waipress/v1/messaging/conversations' });
        setConversations(data.items || []);
      } catch (err) {
        console.error('Failed to load conversations:', err);
      }
      setLoading(false);
    };

    const selectConversation = async (conv) => {
      setSelected(conv);
      try {
        const data = await wp.apiFetch({ path: '/waipress/v1/messaging/conversations/' + conv.id });
        setMessages(data.messages || []);
      } catch (err) {
        console.error('Failed to load messages:', err);
      }
    };

    const sendReply = async () => {
      if (!reply.trim() || !selected) return;
      try {
        await wp.apiFetch({
          path: '/waipress/v1/messaging/conversations/' + selected.id + '/reply',
          method: 'POST',
          data: { content: reply },
        });
        setReply('');
        selectConversation(selected); // Reload messages
      } catch (err) {
        console.error('Failed to send reply:', err);
      }
    };

    const platformIcon = (platform) => {
      const icons = { whatsapp: '\u{1F4F1}', telegram: '\u{2708}\u{FE0F}', instagram: '\u{1F4F7}', webchat: '\u{1F4AC}' };
      return icons[platform] || '\u{2709}\u{FE0F}';
    };

    if (loading) {
      return h('div', { className: 'waipress-loading' });
    }

    return h('div', { style: { display: 'flex', height: '600px', gap: '16px' } },
      // Conversation List
      h('div', { style: { width: '300px', borderRight: '1px solid #c3c4c7', overflowY: 'auto', paddingRight: '16px' } },
        h('h3', { style: { margin: '0 0 12px' } }, 'Conversations'),
        conversations.length === 0
          ? h('p', { style: { color: '#787c82' } }, 'No conversations yet. Connect a messaging channel to get started.')
          : conversations.map(conv =>
              h('div', {
                key: conv.id,
                onClick: () => selectConversation(conv),
                style: {
                  padding: '10px',
                  borderRadius: '4px',
                  cursor: 'pointer',
                  background: selected?.id === conv.id ? '#f0f0f1' : 'transparent',
                  marginBottom: '4px',
                  borderLeft: conv.unread_count > 0 ? '3px solid #2271b1' : '3px solid transparent',
                },
              },
                h('div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center' } },
                  h('strong', null, platformIcon(conv.platform) + ' ' + (conv.contact_name || 'Unknown')),
                  conv.unread_count > 0 && h('span', { className: 'wai-badge' }, conv.unread_count)
                ),
                h('div', { style: { fontSize: '12px', color: '#787c82', marginTop: '4px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } },
                  conv.last_message || 'No messages'
                )
              )
            )
      ),
      // Message Thread
      h('div', { style: { flex: 1, display: 'flex', flexDirection: 'column' } },
        selected
          ? h(wp.element.Fragment, null,
              h('h3', { style: { margin: '0 0 12px' } },
                platformIcon(selected.platform) + ' ' + (selected.contact_name || 'Unknown') +
                ' (' + selected.platform + ')'
              ),
              h('div', {
                style: {
                  flex: 1, overflowY: 'auto', padding: '12px',
                  background: '#f0f0f1', borderRadius: '4px', marginBottom: '12px',
                },
              },
                messages.map((msg, i) =>
                  h('div', {
                    key: i,
                    style: {
                      marginBottom: '8px',
                      textAlign: msg.sender_type === 'agent' ? 'right' : 'left',
                    },
                  },
                    h('div', {
                      style: {
                        display: 'inline-block',
                        padding: '8px 12px',
                        borderRadius: '12px',
                        maxWidth: '70%',
                        background: msg.sender_type === 'agent' ? '#2271b1' : '#fff',
                        color: msg.sender_type === 'agent' ? '#fff' : '#1d2327',
                        boxShadow: '0 1px 2px rgba(0,0,0,0.1)',
                      },
                    }, msg.content),
                    h('div', {
                      style: { fontSize: '11px', color: '#787c82', marginTop: '2px' },
                    }, msg.sender_type + ' - ' + (msg.created_at || ''))
                  )
                )
              ),
              h('div', { style: { display: 'flex', gap: '8px' } },
                h('input', {
                  type: 'text',
                  value: reply,
                  onChange: (e) => setReply(e.target.value),
                  onKeyDown: (e) => e.key === 'Enter' && sendReply(),
                  placeholder: 'Type your reply...',
                  style: { flex: 1, padding: '8px 12px', border: '1px solid #8c8f94', borderRadius: '4px' },
                }),
                h('button', {
                  className: 'button button-primary',
                  onClick: sendReply,
                  disabled: !reply.trim(),
                }, 'Send')
              )
            )
          : h('div', { style: { display: 'flex', alignItems: 'center', justifyContent: 'center', flex: 1, color: '#787c82' } },
              'Select a conversation to view messages'
            )
      )
    );
  };

  // Mount the app
  wp.domReady(function() {
    const container = document.getElementById('waipress-app');
    if (container && container.dataset.page?.includes('messaging')) {
      wp.element.render(h(MessagingInbox), container);
    }
  });
})();
