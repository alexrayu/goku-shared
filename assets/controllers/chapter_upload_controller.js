import { Controller } from '@hotwired/stimulus';

// Sends the archive in slices below the host's upload limit, one after another; the server appends
// them and queues the archive after the last. Without JavaScript the form posts the whole file.
export default class extends Controller {
    static targets = ['file', 'progress', 'error', 'submit'];
    static values = { url: String, token: String, chunk: Number };

    async upload(event) {
        const file = this.fileTarget.files[0];
        if (!file?.size) {
            return;
        }
        event.preventDefault();
        this.submitTarget.disabled = true;
        this.errorTarget.hidden = true;
        this.progressTarget.hidden = false;

        try {
            for (let offset = 0; offset < file.size; offset += this.chunkValue) {
                const body = new FormData();
                body.append('_token', this.tokenValue);
                body.append('name', file.name);
                body.append('size', file.size);
                body.append('offset', offset);
                body.append('chunk', file.slice(offset, offset + this.chunkValue), 'chunk');

                const response = await fetch(this.urlValue, { method: 'POST', body });
                const result = await response.json().catch(() => ({ error: `Upload failed (HTTP ${response.status}).` }));
                if (!response.ok) {
                    throw new Error(result.error);
                }
                this.progressTarget.value = Math.min(offset + this.chunkValue, file.size) / file.size;
                if (result.redirect) {
                    window.location.assign(result.redirect);
                    return;
                }
            }
        } catch (error) {
            this.errorTarget.textContent = error.message;
            this.errorTarget.hidden = false;
            this.progressTarget.hidden = true;
            this.submitTarget.disabled = false;
        }
    }
}
