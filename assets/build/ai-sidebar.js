/**
 * WAIpress AI Sidebar for Block Editor
 *
 * Registers a Gutenberg sidebar plugin panel for AI content generation.
 */
(function() {
  const { registerPlugin } = wp.plugins;
  const { PluginSidebar, PluginSidebarMoreMenuItem } = wp.editor || wp.editPost;
  const { Panel, PanelBody, TextareaControl, Button, Spinner, SelectControl } = wp.components;
  const { useState, useCallback } = wp.element;
  const { useSelect, useDispatch } = wp.data;
  const { Icon } = wp.components;

  const AISidebarPanel = () => {
    const [prompt, setPrompt] = useState('');
    const [loading, setLoading] = useState(false);
    const [result, setResult] = useState('');
    const [mode, setMode] = useState('generate');

    const { editPost } = useDispatch('core/editor');
    const postContent = useSelect((select) => {
      return select('core/editor').getEditedPostContent();
    });
    const postTitle = useSelect((select) => {
      return select('core/editor').getEditedPostAttribute('title');
    });

    const handleGenerate = useCallback(async () => {
      if (!prompt.trim() && mode !== 'seo') return;
      setLoading(true);
      setResult('');

      // Try streaming first via SSE, fallback to REST
      const formData = new FormData();
      formData.append('action', 'waipress_ai_stream');
      formData.append('_nonce', waipressAI.nonce);
      formData.append('mode', mode);
      formData.append('prompt', prompt);
      formData.append('content', postContent || '');
      formData.append('system_prompt', '');

      try {
        const response = await fetch(window.ajaxurl || '/wp-admin/admin-ajax.php', {
          method: 'POST',
          body: formData,
        });

        if (!response.ok) throw new Error('Stream failed');

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let accumulated = '';
        let buffer = '';

        while (true) {
          const { done, value } = await reader.read();
          if (done) break;

          buffer += decoder.decode(value, { stream: true });
          const lines = buffer.split('\n');
          buffer = lines.pop() || '';

          for (const line of lines) {
            if (!line.startsWith('data: ')) continue;
            try {
              const data = JSON.parse(line.slice(6));
              if (data.text) {
                accumulated += data.text;
                setResult(accumulated);
              } else if (data.error) {
                setResult('Error: ' + data.error);
              } else if (data.done) {
                // Stream complete
              }
            } catch (e) { /* ignore parse errors */ }
          }
        }

        if (!accumulated) {
          // Fallback to non-streaming REST endpoint
          const endpoint = mode === 'rewrite' ? 'ai/rewrite' : mode === 'seo' ? 'ai/seo' : 'ai/generate';
          const body = mode === 'rewrite'
            ? { content: postContent, instruction: prompt }
            : mode === 'seo'
            ? { content: postContent, title: postTitle }
            : { prompt: prompt };

          const restResponse = await wp.apiFetch({
            path: '/waipress/v1/' + endpoint,
            method: 'POST',
            data: body,
          });
          setResult(restResponse.output || JSON.stringify(restResponse.seo || restResponse, null, 2));
        }
      } catch (err) {
        // Fallback to REST
        try {
          const endpoint = mode === 'rewrite' ? 'ai/rewrite' : mode === 'seo' ? 'ai/seo' : 'ai/generate';
          const body = mode === 'rewrite'
            ? { content: postContent, instruction: prompt }
            : mode === 'seo'
            ? { content: postContent, title: postTitle }
            : { prompt: prompt };

          const restResponse = await wp.apiFetch({
            path: '/waipress/v1/' + endpoint,
            method: 'POST',
            data: body,
          });
          setResult(restResponse.output || JSON.stringify(restResponse.seo || restResponse, null, 2));
        } catch (restErr) {
          setResult('Error: ' + (restErr.message || 'Generation failed'));
        }
      } finally {
        setLoading(false);
      }
    }, [prompt, mode, postContent, postTitle]);

    const handleInsert = useCallback(() => {
      if (!result) return;

      if (mode === 'generate') {
        // Insert generated content as blocks
        const blocks = wp.blocks.parse(result);
        if (blocks.length > 0) {
          wp.data.dispatch('core/block-editor').insertBlocks(blocks);
        } else {
          // Fallback: insert as paragraph
          const block = wp.blocks.createBlock('core/paragraph', { content: result });
          wp.data.dispatch('core/block-editor').insertBlocks([block]);
        }
      } else if (mode === 'rewrite') {
        // Replace all content
        const blocks = wp.blocks.parse(result);
        wp.data.dispatch('core/block-editor').resetBlocks(blocks);
      } else if (mode === 'seo') {
        try {
          const seoData = JSON.parse(result);
          if (seoData.seo_title) {
            editPost({ title: seoData.seo_title });
          }
          if (seoData.excerpt) {
            editPost({ excerpt: seoData.excerpt });
          }
        } catch (e) { /* not JSON */ }
      }

      setResult('');
      setPrompt('');
    }, [result, mode, editPost]);

    return wp.element.createElement(
      wp.element.Fragment,
      null,
      wp.element.createElement(
        PluginSidebarMoreMenuItem,
        { target: 'waipress-ai-sidebar', icon: 'superhero' },
        'WAIpress AI'
      ),
      wp.element.createElement(
        PluginSidebar,
        {
          name: 'waipress-ai-sidebar',
          title: 'WAIpress AI',
          icon: 'superhero',
        },
        wp.element.createElement(
          PanelBody,
          { title: 'AI Content Assistant', initialOpen: true },
          wp.element.createElement(SelectControl, {
            label: 'Mode',
            value: mode,
            options: [
              { label: 'Generate New Content', value: 'generate' },
              { label: 'Rewrite Content', value: 'rewrite' },
              { label: 'SEO Optimize', value: 'seo' },
              { label: 'Suggest Tags', value: 'tags' },
            ],
            onChange: setMode,
          }),
          wp.element.createElement(TextareaControl, {
            label: mode === 'generate' ? 'What would you like to write about?'
              : mode === 'rewrite' ? 'Rewrite instructions (e.g., "make it more formal")'
              : mode === 'seo' ? 'Click Generate to optimize SEO'
              : 'Content to analyze',
            value: prompt,
            onChange: setPrompt,
            rows: 4,
            placeholder: mode === 'generate'
              ? 'E.g., "Write a blog post about sustainable fashion trends in 2026"'
              : mode === 'rewrite'
              ? 'E.g., "Make it more casual and add humor"'
              : '',
          }),
          wp.element.createElement(
            Button,
            {
              variant: 'primary',
              onClick: handleGenerate,
              disabled: loading || (!prompt.trim() && mode !== 'seo'),
              style: { marginBottom: '12px', width: '100%', justifyContent: 'center' },
            },
            loading
              ? wp.element.createElement(Spinner, null)
              : 'Generate with AI'
          ),
          result && wp.element.createElement(
            'div',
            { style: { marginTop: '12px' } },
            wp.element.createElement(
              'div',
              {
                style: {
                  background: '#f0f0f1',
                  padding: '12px',
                  borderRadius: '4px',
                  maxHeight: '300px',
                  overflow: 'auto',
                  fontSize: '13px',
                  lineHeight: '1.6',
                  whiteSpace: 'pre-wrap',
                  marginBottom: '8px',
                },
              },
              result
            ),
            wp.element.createElement(
              Button,
              {
                variant: 'secondary',
                onClick: handleInsert,
                style: { width: '100%', justifyContent: 'center' },
              },
              mode === 'rewrite' ? 'Replace Content' : mode === 'seo' ? 'Apply SEO Data' : 'Insert into Editor'
            )
          )
        )
      )
    );
  };

  registerPlugin('waipress-ai-sidebar', {
    render: AISidebarPanel,
    icon: 'superhero',
  });
})();
