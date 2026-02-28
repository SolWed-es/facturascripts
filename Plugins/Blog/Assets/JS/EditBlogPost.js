/**
 * Blog Post SEO Generation with AI
 * Simple JavaScript without dependencies
 */

function generateSEO() {
    // Get form values
    const titleInput = document.querySelector('input[name="title"]');
    const contentInput = document.querySelector('textarea[name="content"]');

    if (!titleInput || !contentInput) {
        alert('No se encontraron los campos de título o contenido');
        return;
    }

    const title = titleInput.value.trim();
    const content = contentInput.value.trim();

    if (!title || !content) {
        alert('Necesitas escribir un título y contenido antes de generar el SEO');
        return;
    }

    // Get button and disable it
    const btn = document.querySelector('button[onclick="generateSEO()"]');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generando SEO...';
    }

    // Generate SEO with AI - use action parameter in current URL
    const currentUrl = window.location.pathname + window.location.search;
    const separator = currentUrl.includes('?') ? '&' : '?';
    const url = currentUrl + separator + 'action=generate-seo';

    fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin', // Include session cookies
        body: JSON.stringify({
            title: title,
            content: content,
            provider: 'groq'
        })
    })
    .then(response => {
        // Check if response is OK
        if (!response.ok) {
            throw new Error('Error del servidor: ' + response.status);
        }

        // Check if response is JSON
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            throw new Error('La respuesta no es JSON. Posible error de autenticación.');
        }

        return response.json();
    })
    .then(data => {
        if (data.success && data.data) {
            // Fill SEO fields
            const metaTitleInput = document.querySelector('input[name="meta_title"]');
            const metaDescInput = document.querySelector('textarea[name="meta_description"]');
            const metaKeywordsInput = document.querySelector('input[name="meta_keywords"]');

            if (metaTitleInput) metaTitleInput.value = data.data.meta_title || '';
            if (metaDescInput) metaDescInput.value = data.data.meta_description || '';
            if (metaKeywordsInput) metaKeywordsInput.value = data.data.meta_keywords || '';

            alert('✅ SEO generado correctamente. Revisa los campos y guarda cuando quieras');

            // Re-enable button
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-magic"></i> Generar SEO con IA';
            }
        } else {
            alert('❌ Error: ' + (data.message || 'No se pudo generar el SEO'));
            // Re-enable button on error
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-magic"></i> Generar SEO con IA';
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('❌ Error: ' + error.message);
        // Re-enable button on error
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-magic"></i> Generar SEO con IA';
        }
    });
}
