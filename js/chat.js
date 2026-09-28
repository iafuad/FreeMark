document.addEventListener('DOMContentLoaded', () => {
    const chatMessages = document.getElementById('chat-messages');
    const chatForm = document.getElementById('chat-form');
    const chatInput = document.getElementById('chat-input');
    const searchInput = document.getElementById('chat-contact-search');
    const contactsList = document.getElementById('chat-contacts-list');
    const backBtn = document.getElementById('chat-back-btn');
    const chatLayout = document.querySelector('.chat-layout');

    // 1. Initial Scroll to Bottom
    function scrollToBottom(smooth = false) {
        if (!chatMessages) return;
        if (smooth) {
            chatMessages.scrollTo({
                top: chatMessages.scrollHeight,
                behavior: 'smooth'
            });
        } else {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
    }
    scrollToBottom(false);

    // 2. Escape HTML utility
    function escapeHtml(str) {
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // 3. Format current time (e.g. "6:35 PM")
    function formatTime(date) {
        let hours = date.getHours();
        let minutes = date.getMinutes();
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12;
        hours = hours ? hours : 12;
        minutes = minutes < 10 ? '0' + minutes : minutes;
        return `${hours}:${minutes} ${ampm}`;
    }

    // 4. AJAX Message Submission
    if (chatForm && chatInput) {
        chatForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const text = chatInput.value.trim();
            if (!text) return;

            const formData = new FormData(chatForm);
            formData.append('ajax', '1');

            // Optimistic bubble append
            const bubble = document.createElement('div');
            bubble.className = 'chat-bubble sent';
            const timeStr = formatTime(new Date());

            bubble.innerHTML = `
                ${escapeHtml(text).replace(/\n/g, '<br>')}
                <div class="chat-msg-time">
                    ${timeStr}
                    <i data-lucide="check" style="width: 12px; height: 12px; display: inline;"></i>
                </div>
            `;

            // Remove empty placeholder if present
            const emptyState = chatMessages.querySelector('.chat-empty-state');
            if (emptyState) {
                emptyState.remove();
            }

            chatMessages.appendChild(bubble);
            chatInput.value = '';
            chatInput.focus();

            scrollToBottom(true);
            if (window.lucide) {
                lucide.createIcons();
            }

            // Update active contact preview in sidebar
            const activeContact = document.querySelector('.chat-contact.active');
            if (activeContact) {
                const previewEl = activeContact.querySelector('.chat-contact-preview');
                const timeEl = activeContact.querySelector('.chat-contact-time');
                if (previewEl) {
                    previewEl.textContent = 'You: ' + text;
                }
                if (timeEl) {
                    timeEl.textContent = 'Just now';
                }
            }

            // Asynchronous dispatch
            try {
                const response = await fetch(chatForm.action || window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                if (!response.ok) {
                    throw new Error('Network error');
                }

                const result = await response.json();
                if (result.status !== 'success') {
                    console.warn('Server responded with:', result);
                }
            } catch (err) {
                console.error('Failed to send message asynchronously:', err);
                // Mark bubble with retry hint
                const timeEl = bubble.querySelector('.chat-msg-time');
                if (timeEl) {
                    timeEl.innerHTML += ' <span style="color: #ef4444;" title="Delivery error">(failed)</span>';
                }
            }
        });
    }

    // 5. Instant Contact Search Filter
    if (searchInput && contactsList) {
        searchInput.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase().trim();
            const contacts = contactsList.querySelectorAll('.chat-contact');
            let matchedCount = 0;

            contacts.forEach(c => {
                const name = (c.dataset.name || '').toLowerCase();
                const preview = (c.dataset.preview || '').toLowerCase();
                const matches = name.includes(query) || preview.includes(query);

                c.style.display = matches ? 'flex' : 'none';
                if (matches) matchedCount++;
            });

            let noMatchEl = document.getElementById('chat-no-contacts');
            if (matchedCount === 0 && query !== '') {
                if (!noMatchEl) {
                    noMatchEl = document.createElement('div');
                    noMatchEl.id = 'chat-no-contacts';
                    noMatchEl.style.padding = '20px';
                    noMatchEl.style.textAlign = 'center';
                    noMatchEl.style.color = 'var(--text-secondary)';
                    noMatchEl.style.fontSize = '0.85rem';
                    noMatchEl.textContent = 'No matching conversations';
                    contactsList.appendChild(noMatchEl);
                }
            } else if (noMatchEl) {
                noMatchEl.remove();
            }
        });
    }

    // 6. Mobile Navigation Toggle
    if (backBtn && chatLayout) {
        backBtn.addEventListener('click', () => {
            chatLayout.classList.remove('conversation-open');
        });
    }
});
