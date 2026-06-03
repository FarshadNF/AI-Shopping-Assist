class AIShoppingAssistant {
    constructor(customConfig) {
        if (window.__AI_SHOPPING_ASSIST_LOADED__) return;
        window.__AI_SHOPPING_ASSIST_LOADED__ = true;

        this.defaults = {
            apiBase: 'http://127.0.0.1:8000',
            catalogRoute: 'index.php?route=extension/opencart/checkout/ai_assistant.getCatalog',
            syncToken: '',
            syncIntervalMs: 10 * 60 * 1000,
            pageLimit: 100,
            maxPages: 100
        };

        this.config = Object.assign({}, this.defaults, customConfig || {});
        this.config.apiBase = String(this.config.apiBase || '').replace(/\/+$/, '');
        
        const storagePrefix = 'ai-shopping-assist:' + location.origin;
        this.keys = {
            lastSync: storagePrefix + ':last-sync',
            conversationId: storagePrefix + ':conversation-id'
        };

        this.init();
    }

    init() {
        this.renderDOM();
        this.cacheElements();
        this.bindEvents();
        
        // همگام‌سازی کاتالوگ با تاخیر کمکی بدون مسدود کردن ترد اصلی
        setTimeout(() => {
            this.syncCatalogIfNeeded(false).catch(error => {
                this.setStatus('خطا در بروزرسانی');
                console.warn('AI catalog sync failed:', error);
            });
        }, 600);
    }

    renderDOM() {
        this.root = document.createElement('div');
        this.root.id = 'ai-shopping-assist-widget';
        this.root.innerHTML = `
            <section class="aisa-panel" hidden aria-live="polite">
              <header class="aisa-head">
                <div>
                  <div class="aisa-title">دستیار خرید</div>
                  <span class="aisa-status">آماده</span>
                </div>
                <button type="button" class="aisa-close" aria-label="بستن">×</button>
              </header>
              <div class="aisa-messages"></div>
              <form class="aisa-form">
                <input class="aisa-input" type="text" autocomplete="off" placeholder="سوالت درباره محصول..." />
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
        item.textContent = this.stripAction(text);
        this.messagesContainer.appendChild(item);
        this.messagesContainer.scrollTop = this.messagesContainer.scrollHeight;
    }

    stripAction(text) {
        return String(text || '').replace(/\[ACTION:\s*ADD_TO_CART:[^\]]+\]/gi, '').trim();
    }

    async askAssistant(message) {
        this.sendBtn.disabled = true;
        this.setStatus('در حال پاسخ');

        try {
            await this.syncCatalogIfNeeded(false);

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

            const data = await response.json();
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
                    this.addMessage('bot', 'محصول به سبد خرید اضافه شد.');
                }
            }

            this.setStatus('آماده');
        } catch (error) {
            console.error('AI assistant error:', error);
            this.addMessage('bot', 'ارتباط با دستیار برقرار نشد. لطفا چند لحظه دیگر دوباره امتحان کن.');
            this.setStatus('خطا');
        } finally {
            this.sendBtn.disabled = false;
            this.input.focus();
        }
    }

    async syncCatalogIfNeeded(force) {
        const lastSync = Number(localStorage.getItem(this.keys.lastSync) || 0);
        if (!force && lastSync && Date.now() - lastSync < Number(this.config.syncIntervalMs)) {
            return;
        }

        this.setStatus('بروزرسانی کاتالوگ');
        const products = await this.crawlOpenCartCatalog();
        if (!products.length) {
            throw new Error('OpenCart catalog returned no products');
        }

        const headers = { 'Content-Type': 'application/json' };
        if (this.config.syncToken) {
            headers['X-AI-Assistant-Token'] = this.config.syncToken;
        }

        const response = await fetch(this.config.apiBase + '/api/catalog/import/', {
            method: 'POST',
            mode: 'cors',
            headers: headers,
            body: JSON.stringify({
                source: location.origin,
                products: products
            })
        });

        const data = await response.json();
        if (!response.ok || data.status !== 'success') {
            throw new Error(data.reply || 'Catalog import failed');
        }

        localStorage.setItem(this.keys.lastSync, String(Date.now()));
        this.setStatus('کاتالوگ تازه است');
    }

    async crawlOpenCartCatalog() {
        const collected = [];
        const seen = new Set();
        const pageLimit = Number(this.config.pageLimit) || 100;
        const maxPages = Number(this.config.maxPages) || 100;

        for (let page = 1; page <= maxPages; page += 1) {
            const url = this.buildShopUrl(this.config.catalogRoute);
            url.searchParams.set('page', String(page));
            url.searchParams.set('limit', String(pageLimit));

            const response = await fetch(url.toString(), { credentials: 'same-origin' });
            if (!response.ok) {
                throw new Error('Catalog request failed: ' + response.status);
            }

            const payload = await response.json();
            if (payload.success === false) {
                throw new Error(payload.error || 'OpenCart catalog API failed');
            }

            const rows = this.extractProductRows(payload);
            if (!rows.length) break;

            rows.map(item => this.normalizeProduct(item)).forEach(product => {
                if (!product.name) return;
                const key = product.product_id || product.name;
                if (seen.has(key)) return;
                seen.add(key);
                collected.push(product);
            });

            const total = Number(payload.total || (payload.pagination && payload.pagination.total) || 0);
            if (total && collected.length >= total) break;
            if (!total && rows.length < pageLimit) break;
        }

        return collected;
    }

    extractProductRows(payload) {
        if (Array.isArray(payload)) return payload;
        if (Array.isArray(payload.data)) return payload.data;
        if (Array.isArray(payload.products)) return payload.products;
        if (payload.data && Array.isArray(payload.data.products)) return payload.data.products;
        return [];
    }

    normalizeProduct(item) {
        const name = this.cleanText(this.firstPresent(item, ['name', 'product_name', 'title'], ''));
        return {
            product_id: this.cleanText(this.firstPresent(item, ['product_id', 'id', 'productId'], '')),
            name: name,
            price: this.cleanText(this.firstPresent(item, ['price', 'special'], '0')),
            stock: this.toInt(this.firstPresent(item, ['stock', 'quantity', 'qty'], 0)),
            category: this.cleanText(this.firstPresent(item, ['category', 'category_name', 'manufacturer'], 'Industrial Automation & Networking')),
            attributes: this.normalizeAttributes(this.firstPresent(item, ['attributes', 'attribute_groups', 'specifications'], null)),
            sales_angle: this.cleanText(this.firstPresent(item, ['sales_angle', 'description'], '')),
            alternatives: Array.isArray(item.alternatives) ? item.alternatives : []
        };
    }

    firstPresent(source, names, fallback) {
        for (const name of names) {
            if (source && source[name] !== undefined && source[name] !== null && source[name] !== '') {
                return source[name];
            }
        }
        return fallback;
    }

    cleanText(value) {
        const text = String(value === undefined || value === null ? '' : value);
        const div = document.createElement('div');
        div.innerHTML = text;
        return (div.textContent || div.innerText || '').trim();
    }

    toInt(value) {
        const number = Number(String(value === undefined || value === null ? 0 : value).replace(/,/g, ''));
        return Number.isFinite(number) ? Math.trunc(number) : 0;
    }

    normalizeAttributes(raw) {
        const out = {};

        if (raw && !Array.isArray(raw) && typeof raw === 'object') {
            Object.keys(raw).forEach(key => {
                const name = this.cleanText(key);
                const value = this.cleanText(raw[key]);
                if (name && value) out[name] = value;
            });
            return Object.keys(out).length ? out : {
                Interface: 'مشخصات پورت یافت نشد',
                Protection: 'استاندارد بدنه نامشخص'
            };
        }

        if (Array.isArray(raw)) {
            raw.forEach(entry => {
                if (!entry || typeof entry !== 'object') return;

                const nested = this.firstPresent(entry, ['attribute', 'attributes', 'items'], null);
                if (Array.isArray(nested)) {
                    Object.assign(out, this.normalizeAttributes(nested));
                    return;
                }

                const name = this.cleanText(this.firstPresent(entry, ['name', 'title', 'attribute_name'], ''));
                const value = this.cleanText(this.firstPresent(entry, ['text', 'value', 'attribute_value'], ''));
                if (name && value) out[name] = value;
            });
        }

        return Object.keys(out).length ? out : {
            Interface: 'مشخصات پورت یافت نشد',
            Protection: 'استاندارد بدنه نامشخص'
        };
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