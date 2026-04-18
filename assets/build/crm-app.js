/**
 * WAIpress CRM SPA
 * Contact management, deal pipeline, and activity tracking.
 */
(function() {
  const { createElement: h, useState, useEffect } = wp.element;

  const CRMApp = () => {
    const [contacts, setContacts] = useState([]);
    const [deals, setDeals] = useState([]);
    const [stages, setStages] = useState([]);
    const [view, setView] = useState('contacts'); // contacts | deals
    const [loading, setLoading] = useState(true);

    useEffect(() => {
      loadData();
    }, []);

    const loadData = async () => {
      setLoading(true);
      try {
        const [contactsData, dealsData, stagesData] = await Promise.all([
          wp.apiFetch({ path: '/waipress/v1/crm/contacts' }),
          wp.apiFetch({ path: '/waipress/v1/crm/deals' }),
          wp.apiFetch({ path: '/waipress/v1/crm/deal-stages' }),
        ]);
        setContacts(contactsData.items || []);
        setDeals(dealsData || []);
        setStages(stagesData || []);
      } catch (err) {
        console.error('Failed to load CRM data:', err);
      }
      setLoading(false);
    };

    const page = document.getElementById('waipress-app')?.dataset?.page || '';
    const activeView = page.includes('deals') ? 'deals' : 'contacts';

    if (loading) {
      return h('div', { className: 'waipress-loading' });
    }

    if (activeView === 'deals') {
      // Deal Pipeline (Kanban)
      return h('div', null,
        h('h2', { style: { margin: '0 0 16px' } }, 'Deal Pipeline'),
        h('div', { style: { display: 'flex', gap: '16px', overflowX: 'auto', paddingBottom: '16px' } },
          stages.map(stage =>
            h('div', {
              key: stage.id,
              style: {
                minWidth: '250px',
                background: '#f0f0f1',
                borderRadius: '8px',
                padding: '12px',
                borderTop: '3px solid ' + stage.color,
              },
            },
              h('h3', { style: { margin: '0 0 12px', fontSize: '14px' } },
                stage.name + ' (' + deals.filter(d => d.stage_id == stage.id).length + ')'
              ),
              deals.filter(d => d.stage_id == stage.id).map(deal =>
                h('div', {
                  key: deal.id,
                  style: {
                    background: '#fff',
                    padding: '10px',
                    borderRadius: '4px',
                    marginBottom: '8px',
                    boxShadow: '0 1px 3px rgba(0,0,0,0.1)',
                  },
                },
                  h('strong', null, deal.title),
                  h('div', { style: { fontSize: '12px', color: '#787c82', marginTop: '4px' } },
                    deal.contact_name || 'No contact'
                  ),
                  deal.value_cents > 0 && h('div', { style: { fontSize: '13px', fontWeight: 600, marginTop: '4px', color: '#2271b1' } },
                    (deal.currency || 'EUR') + ' ' + (deal.value_cents / 100).toFixed(2)
                  )
                )
              )
            )
          )
        )
      );
    }

    // Contacts List
    return h('div', null,
      h('h2', { style: { margin: '0 0 16px' } }, 'CRM Contacts'),
      h('table', { className: 'wp-list-table widefat fixed striped' },
        h('thead', null,
          h('tr', null,
            h('th', null, 'Name'),
            h('th', null, 'Email'),
            h('th', null, 'Phone'),
            h('th', null, 'Company'),
            h('th', null, 'Source'),
            h('th', null, 'Created'),
          )
        ),
        h('tbody', null,
          contacts.length === 0
            ? h('tr', null, h('td', { colSpan: 6, style: { textAlign: 'center', padding: '20px' } },
                'No contacts yet. They will appear when customers message you.'))
            : contacts.map(c =>
                h('tr', { key: c.id },
                  h('td', null, h('strong', null, c.name || 'Unknown')),
                  h('td', null, c.email || '-'),
                  h('td', null, c.phone || '-'),
                  h('td', null, c.company || '-'),
                  h('td', null, h('span', {
                    style: {
                      padding: '2px 8px', borderRadius: '10px', fontSize: '11px',
                      background: c.source === 'whatsapp' ? '#25D366' : c.source === 'telegram' ? '#0088cc' : c.source === 'instagram' ? '#E4405F' : '#8c8f94',
                      color: '#fff',
                    },
                  }, c.source)),
                  h('td', null, c.created_at?.split('T')[0] || c.created_at?.split(' ')[0] || '-'),
                )
              )
        )
      )
    );
  };

  wp.domReady(function() {
    const container = document.getElementById('waipress-app');
    if (container && (container.dataset.page?.includes('crm') || container.dataset.page?.includes('deals'))) {
      wp.element.render(h(CRMApp), container);
    }
  });
})();
