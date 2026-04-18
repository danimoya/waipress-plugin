/**
 * WAIpress Media AI - Image Generation in Media Library
 *
 * Adds "Generate with AI" button to the media library grid view.
 */
(function() {
  if (!window.wp || !window.wp.media) return;

  // Wait for media library to be ready
  wp.domReady(function() {
    const toolbar = document.querySelector('.media-toolbar-secondary');
    if (!toolbar) return;

    // Create "Generate with AI" button
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'button media-button button-primary';
    btn.textContent = 'Generate with AI';
    btn.style.marginLeft = '8px';

    btn.addEventListener('click', function() {
      openAIImageModal();
    });

    toolbar.appendChild(btn);
  });

  function openAIImageModal() {
    // Simple modal for image generation
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:160000;display:flex;align-items:center;justify-content:center;';

    const modal = document.createElement('div');
    modal.style.cssText = 'background:#fff;border-radius:8px;padding:24px;width:500px;max-width:90vw;';
    modal.innerHTML = `
      <h2 style="margin:0 0 16px">Generate Image with AI</h2>
      <div style="margin-bottom:12px">
        <label style="display:block;margin-bottom:4px;font-weight:600;">Describe the image you want:</label>
        <textarea id="wai-img-prompt" rows="3" style="width:100%;padding:8px;border:1px solid #8c8f94;border-radius:4px;"
          placeholder="E.g., A professional photo of a modern office space with plants and natural light"></textarea>
      </div>
      <div style="display:flex;gap:12px;margin-bottom:16px;">
        <div style="flex:1">
          <label style="display:block;margin-bottom:4px;font-weight:600;">Style:</label>
          <select id="wai-img-style" style="width:100%;padding:6px;">
            <option value="photo">Photo</option>
            <option value="illustration">Illustration</option>
            <option value="3d">3D Render</option>
            <option value="painting">Painting</option>
            <option value="sketch">Sketch</option>
          </select>
        </div>
        <div style="flex:1">
          <label style="display:block;margin-bottom:4px;font-weight:600;">Size:</label>
          <select id="wai-img-size" style="width:100%;padding:6px;">
            <option value="1024">1024x1024</option>
            <option value="512">512x512</option>
            <option value="1536">1536x1024</option>
          </select>
        </div>
      </div>
      <div id="wai-img-status" style="display:none;padding:12px;background:#f0f0f1;border-radius:4px;margin-bottom:12px;text-align:center;"></div>
      <div style="display:flex;gap:8px;justify-content:flex-end;">
        <button type="button" id="wai-img-cancel" class="button">Cancel</button>
        <button type="button" id="wai-img-generate" class="button button-primary">Generate</button>
      </div>
    `;

    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    // Event handlers
    document.getElementById('wai-img-cancel').addEventListener('click', () => overlay.remove());
    overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });

    document.getElementById('wai-img-generate').addEventListener('click', async () => {
      const prompt = document.getElementById('wai-img-prompt').value.trim();
      if (!prompt) return;

      const style = document.getElementById('wai-img-style').value;
      const size = parseInt(document.getElementById('wai-img-size').value);
      const statusEl = document.getElementById('wai-img-status');
      const genBtn = document.getElementById('wai-img-generate');

      genBtn.disabled = true;
      genBtn.textContent = 'Generating...';
      statusEl.style.display = 'block';
      statusEl.textContent = 'Queuing image generation...';

      try {
        const response = await wp.apiFetch({
          path: '/waipress/v1/ai/images/generate',
          method: 'POST',
          data: { prompt, style, width: size, height: size },
        });

        statusEl.textContent = 'Image generation queued (Job #' + response.id + '). Checking status...';

        // Poll for completion
        const jobId = response.id;
        const checkStatus = async () => {
          const status = await wp.apiFetch({
            path: '/waipress/v1/ai/images/status/' + jobId,
          });

          if (status.status === 'completed' && status.image_url) {
            statusEl.innerHTML = '<strong>Done!</strong> Image added to your media library.';
            genBtn.textContent = 'Generate Another';
            genBtn.disabled = false;

            // Refresh media library
            if (wp.media.frame) {
              wp.media.frame.content.get().collection.props.set({ ignore: +new Date() });
            }
          } else {
            statusEl.textContent = 'Still generating... (checking again in 5s)';
            setTimeout(checkStatus, 5000);
          }
        };
        setTimeout(checkStatus, 5000);

      } catch (err) {
        statusEl.textContent = 'Error: ' + (err.message || 'Generation failed');
        genBtn.disabled = false;
        genBtn.textContent = 'Generate';
      }
    });
  }
})();
