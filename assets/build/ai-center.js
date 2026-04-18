/**
 * WAIpress AI Center
 * Hub for AI content generation, prompt templates, and generation log.
 */
(function() {
  const { createElement: h, useState, useEffect } = wp.element;

  const AICenter = () => {
    const [prompt, setPrompt] = useState('');
    const [result, setResult] = useState('');
    const [loading, setLoading] = useState(false);
    const [templates, setTemplates] = useState([]);
    const [generations, setGenerations] = useState([]);

    const page = document.getElementById('waipress-app')?.dataset?.page || '';

    useEffect(() => {
      if (page.includes('prompts')) {
        wp.apiFetch({ path: '/waipress/v1/ai/prompts' }).then(setTemplates).catch(console.error);
      } else if (page.includes('log')) {
        wp.apiFetch({ path: '/waipress/v1/ai/generations' }).then(d => setGenerations(d.items || [])).catch(console.error);
      }
    }, []);

    const generate = async () => {
      if (!prompt.trim()) return;
      setLoading(true);
      setResult('');
      try {
        const data = await wp.apiFetch({
          path: '/waipress/v1/ai/generate',
          method: 'POST',
          data: { prompt },
        });
        setResult(data.output || 'No output');
      } catch (err) {
        setResult('Error: ' + (err.message || 'Failed'));
      }
      setLoading(false);
    };

    if (page.includes('log')) {
      return h('div', null,
        h('h2', { style: { margin: '0 0 16px' } }, 'AI Generation Log'),
        h('table', { className: 'wp-list-table widefat fixed striped' },
          h('thead', null,
            h('tr', null,
              h('th', null, 'ID'), h('th', null, 'Type'), h('th', null, 'User'),
              h('th', null, 'Model'), h('th', null, 'Tokens'), h('th', null, 'Date'),
            )
          ),
          h('tbody', null,
            generations.map(g =>
              h('tr', { key: g.id },
                h('td', null, g.id),
                h('td', null, g.generation_type),
                h('td', null, g.user_name || 'System'),
                h('td', null, g.model),
                h('td', null, (g.input_tokens || 0) + '+' + (g.output_tokens || 0)),
                h('td', null, g.created_at),
              )
            )
          )
        )
      );
    }

    if (page.includes('prompts')) {
      return h('div', null,
        h('h2', { style: { margin: '0 0 16px' } }, 'Prompt Templates'),
        templates.map(t =>
          h('div', {
            key: t.id,
            style: { background: '#f9f9f9', border: '1px solid #c3c4c7', borderRadius: '4px', padding: '12px', marginBottom: '12px' },
          },
            h('strong', null, t.name),
            t.is_system && h('span', { style: { marginLeft: '8px', fontSize: '11px', color: '#787c82' } }, '(System)'),
            h('div', { style: { fontSize: '13px', color: '#787c82', marginTop: '4px' } }, t.description),
            h('div', { style: { fontSize: '12px', marginTop: '4px' } },
              h('strong', null, 'Category: '), t.category, ' | ',
              h('strong', null, 'Model: '), t.model
            )
          )
        )
      );
    }

    // Default: Generate Content
    return h('div', null,
      h('h2', { style: { margin: '0 0 16px' } }, 'AI Content Generator'),
      h('textarea', {
        value: prompt,
        onChange: (e) => setPrompt(e.target.value),
        rows: 4,
        placeholder: 'Describe what you want to generate...\nE.g., "Write a blog post about the benefits of remote work in 2026"',
        style: { width: '100%', padding: '12px', border: '1px solid #8c8f94', borderRadius: '4px', fontSize: '14px', marginBottom: '12px' },
      }),
      h('button', {
        className: 'button button-primary button-hero',
        onClick: generate,
        disabled: loading || !prompt.trim(),
      }, loading ? 'Generating...' : 'Generate with AI'),
      result && h('div', { style: { marginTop: '16px', padding: '16px', background: '#f0f0f1', borderRadius: '4px', whiteSpace: 'pre-wrap', lineHeight: '1.6' } },
        h('div', { style: { display: 'flex', justifyContent: 'space-between', marginBottom: '8px' } },
          h('strong', null, 'Generated Content:'),
          h('button', {
            className: 'button button-small',
            onClick: () => navigator.clipboard.writeText(result),
          }, 'Copy')
        ),
        result
      )
    );
  };

  wp.domReady(function() {
    const container = document.getElementById('waipress-app');
    if (container && container.dataset.page?.includes('waipress-ai')) {
      wp.element.render(h(AICenter), container);
    }
  });
})();
