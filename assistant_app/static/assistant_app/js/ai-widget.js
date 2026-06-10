class AIShoppingAssistant {
    constructor(customConfig) {
        if (window.__AI_SHOPPING_ASSIST_LOADED__) return;
        window.__AI_SHOPPING_ASSIST_LOADED__ = true;

        this.defaults = {
            // [حل چالش ۴]: عدم استفاده از هاردکد http برای جلوگیری از Mixed Content
            // بهتر است این مقدار از طریق پنل مدیریت به ویجت پاس داده شود
            apiBase: '', 
        };

        this.config = Object.assign({}, this.defaults, customConfig || {});
        this.config.apiBase = String(this.config.apiBase || '').replace(/\/+$/, '');
        
        const storagePrefix = 'ai-shopping-assist:' + location.origin;
        this.keys = {
            conversationId: storagePrefix + ':conversation-id'
        };

        this.init();
    }

    init() {
        this.renderDOM();
        this.cacheElements();
        this.bindEvents();
        // [حل چالش ۳ و ۲]: تابع خطرناک همگام‌سازی کاتالوگ و توکن امنیتی کاملاً حذف شد.
    }

    renderDOM() {
        this.root = document.createElement('div');
        this.root.id = 'ai-shopping-assist-widget';
        this.root.innerHTML = `
            <section class="aisa-panel" hidden aria-live="polite">
              <header class="aisa-head">
                <div>
                  <div class="aisa-title">دستیار هوشمند خرید</div>
                  <span class="aisa-status">آماده پاسخگویی</span>
                </div>
                <button type="button" class="aisa-close" aria-label="بستن">×</button>
              </header>
              <div class="aisa-messages"></div>
              <form class="aisa-form">
                <input class="aisa-input" type="text" autocomplete="off" placeholder="سوالت درباره محصولات..." required />
                <button class="aisa-send" type="submit">ارسال</button>
              </form>
            </section>
            <button type="button" class="aisa-toggle">دستیار خرید</button>
        `;
        document.body.appendChild(this.root);
    }

    cacheElements() {
        this.panel = this.root.querySelector('.aisa-panel');
        this.toggleBtn = this.root.querySelector('.aisa-toggle');
        this.closeBtn = this.root.querySelector('.aisa-close');
        this.messagesContainer = this.root.querySelector('.aisa-messages');
        this.form = this.root.querySelector('.aisa-form');
        this.input = this.root.querySelector('.aisa-input');
        this.sendBtn = this.root.querySelector('.aisa-send');
        this.statusLabel = this.root.querySelector('.aisa-status');
    }

    bindEvents() {
        this.toggleBtn.addEventListener('click', () => {
            this.panel.hidden = false;
            this.toggleBtn.hidden = true;
            this.input.focus();
        });

        this.closeBtn.addEventListener('click', () => {
            this.panel.hidden = true;
            this.toggleBtn.hidden = false;
        });

        this.form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const text = this.input.value.trim();
            if (!text) return;

            this.input.value = '';
            this.addMessage('user', text);
            await this.askAssistant(text);
        });
    }

    getShopBaseUrl() {
        const base = document.querySelector('base[href]');
        return base ? base.href : location.origin + '/';
    }

    buildShopUrl(route) {
        return new URL(route, this.getShopBaseUrl());
    }

    setStatus(text) {
        this.statusLabel.textContent = text;
    }

    addMessage(role, text) {
        const item = document.createElement('div');
        item.className = 'aisa-message ' + (role === 'user' ? 'user' : 'bot');
        
        // [حل چالش ۱]: جلوگیری از حمله XSS. ویژگی textContent هرگز تگ‌های HTML را اجرا نمی‌کند
        // و آنها را فقط به عنوان متن ساده چاپ می‌کند. (سریع‌ترین و امن‌ترین راه)
        item.textContent = this.stripAction(text);
        
        this.messagesContainer.appendChild(item);
        this.messagesContainer.scrollTop = this.messagesContainer.scrollHeight;
    }

    stripAction(text) {
        return String(text || '').replace(/\[ACTION:\s*ADD_TO_CART:[^\]]+\]/gi, '').trim();
    }

    async askAssistant(message) {
        this.sendBtn.disabled = true;
        this.setStatus('در حال تایپ...');

        try {
            const body = { message: message };
            const conversationId = localStorage.getItem(this.keys.conversationId);
            if (conversationId) {
                body.conversation_id = conversationId;
            }

            const response = await fetch(this.config.apiBase + '/api/chat/', {
                method: 'POST',
                mode: 'cors',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });

            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.status !== 'success') {
                throw new Error(data.reply || 'Chat request failed');
            }

            if (data.conversation_id) {
                localStorage.setItem(this.keys.conversationId, data.conversation_id);
            }

            this.addMessage('bot', data.reply);

            if (data.action && data.action.product_id) {
                const added = await this.addToCart(data.action.product_id);
                if (added) {
                    this.addMessage('bot', '✅ محصول با موفقیت به سبد خرید شما اضافه شد.');
                }
            }

        } catch (error) {
            console.error('AI assistant error:', error);
            this.addMessage('bot', 'ارتباط با دستیار برقرار نشد. لطفا ارتباط اینترنت را بررسی کنید.');
        } finally {
            this.setStatus('آماده پاسخگویی');
            this.sendBtn.disabled = false;
            this.input.focus();
        }
    }

    async addToCart(productId) {
        const routes = [
            'index.php?route=checkout/cart.add',
            'index.php?route=checkout/cart/add'
        ];
        const body = new URLSearchParams({
            product_id: productId,
            quantity: '1'
        });

        for (const route of routes) {
            try {
                const response = await fetch(this.buildShopUrl(route).toString(), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body
                });
                if (response.ok) return true;
            } catch (error) {
                console.warn('OpenCart add-to-cart failed:', error);
            }
        }
        return false;
    }
}