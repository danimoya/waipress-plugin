/**
 * WAIpress Chatbot Admin
 * Configuration editor and live session monitoring.
 */
(function() {
  const { createElement: h, useState, useEffect } = wp.element;

  const ChatbotAdmin = () => {
    const [configs, setConfigs] = useState([]);
    const [sessions, setSessions] = useState([]);
    const [loading, setLoading] = useState(true);
    const [editing, setEditing] = useState(null);

    useEffect(() => {
      loadData();
    }, []);

    const loadData = async () => {
      setLoading(true);
      try {
        const [configsData, sessionsData] = await Promise.all([
          wp.apiFetch({ path: '/waipress/v1/chatbot/configs' }),
          wp.apiFetch({ path: '/waipress/v1/chatbot/sessions?status=active' }),
        ]);
        setConfigs(configsData || []);
        setSessions(sessionsData || []);
      } catch (err) {
        console.error('Failed to load chatbot data:', err);
      }
      setLoading(false);
    };

    const page = document.getElementById('waipress-app')?.dataset?.page || '';

    if (loading) {
      return h('div', { className: 'waipress-loading' });
    }

    if (page.includes('live')) {
      // Live Sessions View
      return h('div', null,
        h('h2', { style: { margin: '0 0 16px' } }, 'Live Chatbot Sessions'),
        sessions.length === 0
          ? h('p', { style: { color: '#787c82' } }, 'No active chatbot sessions right now.')
          : h('table', { className: 'wp-list-table widefat fixed striped' },
              h('thead', null,
                h('tr', null,
                  h('th', null, 'Session ID'),
                  h('th', null, 'Visitor'),
                  h('th', null, 'Config'),
                  h('th', null, 'Messages'),
                  h('th', null, 'Started'),
                  h('th', null, 'Actions'),
                )
              ),
              h('tbody', null,
                sessions.map(s =>
                  h('tr', { key: s.id },
                    h('td', null, '#' + s.id),
                    h('td', null, s.contact_name || s.visitor_id?.slice(0, 8) || 'Anonymous'),
                    h('td', null, s.config_name || 'Default'),
                    h('td', null, s.message_count || 0),
                    h('td', null, s.started_at?.split(' ')[1] || s.started_at || '-'),
                    h('td', null,
                      h('button', {
                        className: 'button button-small',
                        onClick: async () => {
                          await wp.apiFetch({
                            path: '/waipress/v1/chatbot/sessions/' + s.id + '/takeover',
                            method: 'POST',
                          });
                          alert('Session taken over. Check the Messaging inbox.');
                          loadData();
                        },
                      }, 'Take Over')
                    ),
                  )
                )
              )
            )
      );
    }

    // Config Editor
    return h('div', null,
      h('h2', { style: { margin: '0 0 16px' } }, 'Chatbot Configuration'),
      configs.map(config =>
        h('div', {
          key: config.id,
          style: {
            background: '#f9f9f9',
            border: '1px solid #c3c4c7',
            borderRadius: '4px',
            padding: '16px',
            marginBottom: '16px',
          },
        },
          h('div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '12px' } },
            h('h3', { style: { margin: 0 } }, config.name),
            h('span', {
              style: {
                padding: '2px 8px',
                borderRadius: '10px',
                fontSize: '11px',
                background: config.is_active ? '#00a32a' : '#8c8f94',
                color: '#fff',
              },
            }, config.is_active ? 'Active' : 'Inactive')
          ),
          h('p', { style: { margin: '0 0 8px' } },
            h('strong', null, 'Model: '), config.model
          ),
          h('p', { style: { margin: '0 0 8px' } },
            h('strong', null, 'Welcome: '), config.welcome_message || 'None'
          ),
          h('p', { style: { margin: '0 0 8px' } },
            h('strong', null, 'System prompt: '),
            (config.system_prompt || '').slice(0, 200) + ((config.system_prompt || '').length > 200 ? '...' : '')
          ),
          h('p', { style: { margin: '0 0 8px' } },
            h('strong', null, 'Escalation: '), config.escalation_enabled ? 'Enabled' : 'Disabled'
          ),
        )
      ),
      configs.length === 0 && h('p', { style: { color: '#787c82' } },
        'No chatbot configurations. A default one will be created automatically.'
      )
    );
  };

  wp.domReady(function() {
    const container = document.getElementById('waipress-app');
    if (container && container.dataset.page?.includes('chatbot')) {
      wp.element.render(h(ChatbotAdmin), container);
    }
  });
})();
