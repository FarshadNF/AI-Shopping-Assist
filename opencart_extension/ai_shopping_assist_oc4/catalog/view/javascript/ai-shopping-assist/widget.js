(function () {
  class AIShoppingAssistant {
    constructor(customConfig) {
      if (window.__AI_SHOPPING_ASSIST_LOADED__) return;
      window.__AI_SHOPPING_ASSIST_LOADED__ = true;

      this.defaults = {
        apiBase: '',
        chatRoute: '',
        cartRoute: 'index.php?route=checkout/cart',
        productRoute: 'index.php?route=product/product',
        searchRoute: 'index.php?route=product/search',
        cartInfoRoute: 'index.php?route=extension/ai_shopping_assist/module/ai_shopping_assist.getCart',
        cartActionRoute: 'index.php?route=extension/ai_shopping_assist/module/ai_shopping_assist.cartAction',
        checkoutRoute: 'index.php?route=checkout/checkout',
        couponRoute: 'index.php?route=extension/opencart/total/coupon.save',
        invoiceRoute: 'index.php?route=extension/ai_shopping_assist/module/ai_shopping_assist.sendInvoice',
        title: 'دستیار هوشمند خرید',
        buttonText: 'دستیار خرید',
        avatarUrl: '',
        redirectDelayMs: 700,
        maxHistoryMessages: 80
      };

      this.config = Object.assign({}, this.defaults, customConfig || {});
      this.config.apiBase = String(this.config.apiBase || '').replace(/\/+$/, '');

      const storagePrefix = 'ai-shopping-assist:' + location.origin;
      this.keys = {
        conversationId: storagePrefix + ':conversation-id',
        chatHistory: storagePrefix + ':chat-history',
        cartSnapshot: storagePrefix + ':cart-snapshot'
      };

      this.init();
    }

    init() {
      this.renderDOM();
      this.cacheElements();
      this.restoreChatHistory();
      this.bindEvents();
    }

    renderDOM() {
      this.root = document.createElement('div');
      this.root.id = 'ai-shopping-assist-widget';
      this.root.innerHTML = `
        <section class="aisa-panel" hidden aria-live="polite">
          <header class="aisa-head">
            <div class="aisa-head-main">
              <img class="aisa-avatar" alt="" hidden />
              <div>
                <div class="aisa-title"></div>
                <span class="aisa-status">آماده پاسخگویی</span>
              </div>
            </div>
            <button type="button" class="aisa-close" aria-label="بستن">×</button>
          </header>
          <div class="aisa-messages"></div>
          <form class="aisa-form">
            <input class="aisa-input" type="text" autocomplete="off" placeholder="سوالت درباره محصولات..." required />
            <button class="aisa-send" type="submit">ارسال</button>
          </form>
        </section>
        <button type="button" class="aisa-toggle">
          <img class="aisa-toggle-avatar" alt="" hidden />
          <span class="aisa-toggle-text"></span>
        </button>
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
      this.title = this.root.querySelector('.aisa-title');
      this.avatar = this.root.querySelector('.aisa-avatar');
      this.toggleAvatar = this.root.querySelector('.aisa-toggle-avatar');
      this.toggleText = this.root.querySelector('.aisa-toggle-text');
      this.title.textContent = this.config.title || this.defaults.title;
      this.toggleText.textContent = this.config.buttonText || this.defaults.buttonText;
      this.applyAvatar();
    }

    applyAvatar() {
      const avatarUrl = String(this.config.avatarUrl || '');
      if (!avatarUrl) return;

      [this.avatar, this.toggleAvatar].forEach((image) => {
        image.src = avatarUrl;
        image.hidden = false;
      });
    }

    bindEvents() {
      this.toggleBtn.addEventListener('click', () => {
        this.panel.hidden = false;
        this.toggleBtn.hidden = true;
        this.scrollMessagesToBottom();
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

    getChatUrl() {
      if (this.config.chatRoute) {
        return this.buildShopUrl(this.config.chatRoute).toString();
      }

      return this.config.apiBase + '/api/chat/';
    }

    setStatus(text) {
      this.statusLabel.textContent = text;
    }

    addMessage(role, text, persist) {
      const cleanedText = this.stripAction(text);
      if (!cleanedText) return;

      const item = document.createElement('div');
      item.className = 'aisa-message ' + (role === 'user' ? 'user' : 'bot');
      item.dir = this.isRtlMessage(cleanedText) ? 'rtl' : 'ltr';
      item.textContent = cleanedText;

      this.messagesContainer.appendChild(item);
      this.scrollMessagesToBottom();

      if (persist !== false) {
        this.rememberChatMessage(role, cleanedText);
      }
    }

    loadChatHistory() {
      try {
        const parsed = JSON.parse(localStorage.getItem(this.keys.chatHistory) || '[]');
        if (!Array.isArray(parsed)) return [];

        return parsed.filter((message) => {
          return message
            && (message.role === 'user' || message.role === 'bot')
            && typeof message.text === 'string'
            && message.text.trim();
        });
      } catch (error) {
        return [];
      }
    }

    saveChatHistory(messages) {
      const limit = Math.max(1, Number(this.config.maxHistoryMessages) || 80);
      const trimmed = messages.slice(-limit);
      localStorage.setItem(this.keys.chatHistory, JSON.stringify(trimmed));
    }

    rememberChatMessage(role, text) {
      try {
        const messages = this.loadChatHistory();
        messages.push({
          role: role === 'user' ? 'user' : 'bot',
          text: String(text || ''),
          createdAt: Date.now()
        });
        this.saveChatHistory(messages);
      } catch (error) {
        console.warn('AI assistant could not save chat history:', error);
      }
    }

    restoreChatHistory() {
      const messages = this.loadChatHistory();
      messages.forEach((message) => {
        this.addMessage(message.role, message.text, false);
      });
      this.scrollMessagesToBottom();
    }

    scrollMessagesToBottom() {
      const scroll = () => {
        this.messagesContainer.scrollTop = this.messagesContainer.scrollHeight;
      };

      scroll();
      window.requestAnimationFrame(scroll);
      window.requestAnimationFrame(() => {
        scroll();
        window.setTimeout(scroll, 60);
      });
    }

    stripAction(text) {
      return String(text || '').replace(/\[ACTION:\s*ADD_TO_CART:[^\]]+\]/gi, '').trim();
    }

    isRtlMessage(text) {
      const value = String(text || '');
      const rtlCount = (value.match(/[\u0590-\u08ff]/g) || []).length;
      const latinCount = (value.match(/[A-Za-z]/g) || []).length;
      return rtlCount > latinCount;
    }

    getRequestedQuantity(action) {
      const rawQuantity = action && (action.requested_qty || action.qty || action.quantity);
      const quantity = Number(rawQuantity || 1);
      return Number.isFinite(quantity) && quantity > 0 ? Math.trunc(quantity) : 1;
    }

    getActions(data) {
      if (Array.isArray(data.actions)) return data.actions;
      return data.action ? [data.action] : [];
    }

    localizedAddedMessage(message, quantity) {
      if (this.isRtlMessage(message)) {
        return `${quantity} عدد محصول به سبد خرید شما اضافه شد.`;
      }
      return `Added ${quantity} ${quantity === 1 ? 'item' : 'items'} to your cart.`;
    }

    localizedConnectionError(message) {
      if (this.isRtlMessage(message)) {
        return 'ارتباط با دستیار برقرار نشد. لطفا چند لحظه دیگر دوباره امتحان کنید.';
      }
      return 'I could not connect to the assistant. Please try again in a moment.';
    }

    localizedInvoiceSentMessage(message) {
      return this.isRtlMessage(message) ? 'درخواست فاکتور ثبت شد.' : 'Invoice request sent.';
    }

    localizedInvoiceFallbackMessage(message) {
      if (this.isRtlMessage(message)) {
        return 'ارسال خودکار فاکتور در دسترس نیست. شما را به صفحه پرداخت منتقل می‌کنم.';
      }
      return 'Automatic invoice sending is not available. I will take you to checkout.';
    }

    localizedCartEmptyMessage(message) {
      return this.isRtlMessage(message) ? 'سبد خرید شما خالی است.' : 'Your basket is empty.';
    }

    localizedCartUnavailableMessage(message) {
      if (this.isRtlMessage(message)) {
        return 'فعلا نمی‌توانم سبد خرید زنده را بخوانم. صفحه سبد خرید را باز می‌کنم.';
      }
      return 'I cannot read the live basket yet. I will open the basket page.';
    }

    localizedUpdatedMessage(message) {
      return this.isRtlMessage(message) ? 'سبد خرید به‌روزرسانی شد.' : 'Your basket has been updated.';
    }

    localizedRemovedMessage(message) {
      return this.isRtlMessage(message) ? 'محصول از سبد خرید حذف شد.' : 'Removed the item from your basket.';
    }

    localizedClearedMessage(message) {
      return this.isRtlMessage(message) ? 'سبد خرید خالی شد.' : 'Your basket has been cleared.';
    }

    localizedCouponMessage(message) {
      return this.isRtlMessage(message) ? 'کد تخفیف اعمال شد.' : 'Coupon applied.';
    }

    localizedActionFailedMessage(message) {
      if (this.isRtlMessage(message)) {
        return 'برای انجام این کار باید صفحه سبد خرید را باز کنم.';
      }
      return 'I need to open the basket page to complete that.';
    }

    async askAssistant(message) {
      this.sendBtn.disabled = true;
      this.setStatus('در حال تایپ...');

      try {
        const body = {
          message: message,
          page_context: {
            url: window.location.href,
            title: document.title
          }
        };
        const conversationId = localStorage.getItem(this.keys.conversationId);
        if (conversationId) {
          body.conversation_id = conversationId;
        }

        const viaProxy = Boolean(this.config.chatRoute);
        const response = await fetch(this.getChatUrl(), {
          method: 'POST',
          mode: viaProxy ? 'same-origin' : 'cors',
          credentials: viaProxy ? 'same-origin' : 'omit',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body)
        });

        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.status !== 'success') {
          if (data.conversation_id) {
            localStorage.setItem(this.keys.conversationId, data.conversation_id);
          }
          if (data.reply) {
            this.addMessage('bot', data.reply);
            return;
          }
          throw new Error(data.reply || 'Chat request failed');
        }

        if (data.conversation_id) {
          localStorage.setItem(this.keys.conversationId, data.conversation_id);
        }

        this.addMessage('bot', data.reply);

        for (const action of this.getActions(data)) {
          await this.handleAction(action, message, data);
        }
      } catch (error) {
        console.error('AI assistant error:', error);
        this.addMessage('bot', this.localizedConnectionError(message));
      } finally {
        this.setStatus('آماده پاسخگویی');
        this.sendBtn.disabled = false;
        this.input.focus();
      }
    }

    async handleAction(action, message, data) {
      const actionType = action.type || (action.product_id ? 'add_to_cart' : '');

      if (actionType === 'add_to_cart' && action.product_id) {
        const quantity = this.getRequestedQuantity(action);
        const added = await this.addToCart(action.product_id, quantity);
        if (added) {
          this.rememberCartItem(action, quantity);
          this.addMessage('bot', this.localizedAddedMessage(message, quantity));
        }
        return;
      }

      if (actionType === 'show_cart') {
        await this.showCart(message);
        return;
      }

      if (actionType === 'redirect_to_cart') {
        this.redirectToRoute(this.config.cartRoute);
        return;
      }

      if (actionType === 'redirect_to_product') {
        this.redirectToProduct(action);
        return;
      }

      if (actionType === 'redirect_to_page') {
        this.redirectToPage(action);
        return;
      }

      if (actionType === 'update_cart_item') {
        const updated = await this.updateCartItem(action);
        this.addMessage('bot', updated ? this.localizedUpdatedMessage(message) : this.localizedActionFailedMessage(message));
        if (!updated) this.redirectToRoute(this.config.cartRoute);
        return;
      }

      if (actionType === 'remove_from_cart') {
        const removed = await this.removeCartItem(action);
        this.addMessage('bot', removed ? this.localizedRemovedMessage(message) : this.localizedActionFailedMessage(message));
        if (!removed) this.redirectToRoute(this.config.cartRoute);
        return;
      }

      if (actionType === 'clear_cart') {
        const cleared = await this.clearCart();
        this.addMessage('bot', cleared ? this.localizedClearedMessage(message) : this.localizedActionFailedMessage(message));
        if (!cleared) this.redirectToRoute(this.config.cartRoute);
        return;
      }

      if (actionType === 'apply_coupon') {
        const applied = await this.applyCoupon(action);
        this.addMessage('bot', applied ? this.localizedCouponMessage(message) : this.localizedActionFailedMessage(message));
        if (!applied) this.redirectToRoute(this.config.cartRoute);
        return;
      }

      if (actionType === 'redirect_to_checkout') {
        this.redirectToRoute(this.config.checkoutRoute);
        return;
      }

      if (actionType === 'send_invoice') {
        const sent = await this.requestInvoice(action, data);
        if (sent) {
          this.addMessage('bot', this.localizedInvoiceSentMessage(message));
          return;
        }
        this.addMessage('bot', this.localizedInvoiceFallbackMessage(message));
        this.redirectToRoute(this.config.checkoutRoute);
      }
    }

    redirectToRoute(route) {
      if (!route) return;
      this.redirectToUrl(this.buildShopUrl(route).toString());
    }

    redirectToPage(action) {
      const url = String(action.url || action.href || '');
      if (url) {
        this.redirectToUrl(url);
        return;
      }

      const route = String(action.route || '');
      if (route) {
        this.redirectToRoute(route);
      }
    }

    redirectToUrl(url) {
      if (!url) return;
      const delay = Number(this.config.redirectDelayMs || 0);
      window.setTimeout(() => {
        try {
          window.location.assign(new URL(url, this.getShopBaseUrl()).toString());
        } catch (error) {
          window.location.assign(url);
        }
      }, Number.isFinite(delay) ? delay : 0);
    }

    redirectToProduct(action) {
      const productUrl = String(action.product_url || action.url || action.href || '');
      if (productUrl) {
        this.redirectToUrl(productUrl);
        return;
      }

      if (action.product_id) {
        const url = this.buildShopUrl(this.config.productRoute);
        url.searchParams.set('product_id', String(action.product_id));
        this.redirectToUrl(url.toString());
        return;
      }

      if (action.product_name) {
        const url = this.buildShopUrl(this.config.searchRoute);
        url.searchParams.set('search', String(action.product_name));
        this.redirectToUrl(url.toString());
      }
    }

    async requestInvoice(action, data) {
      if (!this.config.invoiceRoute) return false;
      const body = new URLSearchParams({
        email: action.email || '',
        invoice_type: action.invoice_type || 'invoice',
        note: action.note || '',
        conversation_id: data.conversation_id || ''
      });

      try {
        const response = await fetch(this.buildShopUrl(this.config.invoiceRoute).toString(), {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body
        });
        const payload = await response.json().catch(() => ({}));
        return response.ok && payload.success !== false && payload.status !== 'error';
      } catch (error) {
        console.warn('OpenCart invoice request failed:', error);
        return false;
      }
    }

    async showCart(message) {
      const cart = await this.getCart();
      if (cart.items.length) {
        this.addMessage('bot', this.formatCartSummary(cart, message));
        return;
      }
      if (cart.source === 'unavailable') {
        this.addMessage('bot', this.localizedCartUnavailableMessage(message));
        this.redirectToRoute(this.config.cartRoute);
        return;
      }
      this.addMessage('bot', this.localizedCartEmptyMessage(message));
    }

    async getCart() {
      const jsonCart = await this.fetchCartJson(this.config.cartInfoRoute);
      if (jsonCart) return jsonCart;

      const htmlCart = await this.fetchCartHtml(this.config.cartRoute);
      if (htmlCart) return htmlCart;

      const localCart = this.loadLocalCart();
      if (localCart.items.length) return Object.assign({ source: 'local' }, localCart);

      return { source: 'unavailable', items: [], totals: [] };
    }

    async fetchCartJson(route) {
      if (!route) return null;
      try {
        const response = await fetch(this.buildShopUrl(route).toString(), {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' }
        });
        const contentType = response.headers.get('content-type') || '';
        if (!response.ok || !contentType.includes('json')) return null;
        const payload = await response.json();
        return this.normalizeCartPayload(payload, 'json');
      } catch (error) {
        console.warn('OpenCart cart JSON request failed:', error);
        return null;
      }
    }

    async fetchCartHtml(route) {
      if (!route) return null;
      try {
        const response = await fetch(this.buildShopUrl(route).toString(), {
          credentials: 'same-origin',
          headers: { Accept: 'text/html' }
        });
        const contentType = response.headers.get('content-type') || '';
        if (!response.ok || !contentType.includes('html')) return null;
        return this.parseCartHtml(await response.text());
      } catch (error) {
        console.warn('OpenCart cart HTML request failed:', error);
        return null;
      }
    }

    normalizeCartPayload(payload, source) {
      const container = payload && (payload.data || payload.cart || payload);
      const rows = this.firstArray(
        container && container.products,
        container && container.items,
        container && container.lines,
        payload && payload.products,
        payload && payload.items
      );
      const items = rows.map((row) => this.normalizeCartItem(row)).filter((item) => item.name);
      const totals = this.firstArray(container && container.totals, payload && payload.totals);
      return { source: source, items: items, totals: totals, total: (container && (container.total || container.total_formatted || container.subtotal)) || '' };
    }

    normalizeCartItem(row) {
      return {
        key: String(row.key || row.cart_id || row.cartId || row.id || ''),
        product_id: String(row.product_id || row.productId || ''),
        name: String(row.name || row.product_name || row.title || '').trim(),
        quantity: this.getRequestedQuantity({ requested_qty: row.quantity || row.qty || 1 }),
        price: String(row.price || row.unit_price || '').trim(),
        total: String(row.total || row.line_total || '').trim()
      };
    }

    parseCartHtml(html) {
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const rows = Array.from(doc.querySelectorAll('form table tbody tr, .table-responsive table tbody tr'));
      const items = rows.map((row) => {
        const quantityInput = row.querySelector('input[name^="quantity"]');
        const keyMatch = quantityInput && String(quantityInput.name || '').match(/\[([^\]]+)\]/);
        const cells = Array.from(row.querySelectorAll('td'));
        const link = row.querySelector('a[href*="product_id"], .text-start a, td a');
        const name = link ? link.textContent.trim() : (cells[1] && cells[1].textContent.trim()) || '';
        return {
          key: keyMatch ? keyMatch[1] : '',
          product_id: this.extractProductId((link && link.href) || ''),
          name: name.replace(/\s+/g, ' '),
          quantity: this.getRequestedQuantity({ requested_qty: quantityInput ? quantityInput.value : 1 }),
          price: (cells[cells.length - 2] && cells[cells.length - 2].textContent.trim()) || '',
          total: (cells[cells.length - 1] && cells[cells.length - 1].textContent.trim()) || ''
        };
      }).filter((item) => item.name);
      return { source: 'html', items: items, totals: [], total: '' };
    }

    extractProductId(url) {
      try {
        return new URL(url, location.href).searchParams.get('product_id') || '';
      } catch (error) {
        return '';
      }
    }

    firstArray(...values) {
      for (const value of values) {
        if (Array.isArray(value)) return value;
      }
      return [];
    }

    formatCartSummary(cart, message) {
      const lines = cart.items.map((item) => {
        const qty = item.quantity || 1;
        const total = item.total || item.price || '';
        return total ? `${qty} x ${item.name} - ${total}` : `${qty} x ${item.name}`;
      });
      const totalLine = cart.total ? `\nTotal: ${cart.total}` : '';
      if (this.isRtlMessage(message)) {
        return `سبد خرید شما:\n${lines.join('\n')}${totalLine}`;
      }
      return `Your basket:\n${lines.join('\n')}${totalLine}`;
    }

    loadLocalCart() {
      try {
        const parsed = JSON.parse(localStorage.getItem(this.keys.cartSnapshot) || '[]');
        return { source: 'local', items: Array.isArray(parsed) ? parsed : [], totals: [], total: '' };
      } catch (error) {
        return { source: 'local', items: [], totals: [], total: '' };
      }
    }

    saveLocalCart(items) {
      localStorage.setItem(this.keys.cartSnapshot, JSON.stringify(items));
    }

    rememberCartItem(action, quantity) {
      const cart = this.loadLocalCart();
      const productId = String(action.product_id || '');
      const existing = cart.items.find((item) => productId && String(item.product_id) === productId);
      if (existing) {
        existing.quantity = Number(existing.quantity || 0) + quantity;
      } else {
        cart.items.push({
          key: '',
          product_id: productId,
          name: action.product_name || productId,
          quantity: quantity,
          price: action.price || '',
          total: ''
        });
      }
      this.saveLocalCart(cart.items);
    }

    findCartItem(cart, action) {
      const productId = String(action.product_id || '');
      const name = String(action.product_name || '').toLowerCase();
      return cart.items.find((item) => {
        if (productId && String(item.product_id) === productId) return true;
        return name && String(item.name || '').toLowerCase().includes(name);
      });
    }

    async postCartAction(actionType, params) {
      if (!this.config.cartActionRoute) return false;
      const body = new URLSearchParams(Object.assign({ action: actionType }, params || {}));
      try {
        const response = await fetch(this.buildShopUrl(this.config.cartActionRoute).toString(), {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body
        });
        const payload = await response.json().catch(() => ({}));
        return response.ok && payload.success !== false && payload.status !== 'error';
      } catch (error) {
        console.warn('OpenCart cart action failed:', error);
        return false;
      }
    }

    async updateCartItem(action) {
      const quantity = this.getRequestedQuantity(action);
      const cart = await this.getCart();
      const item = this.findCartItem(cart, action);
      const params = {
        product_id: action.product_id || (item && item.product_id) || '',
        key: (item && item.key) || '',
        quantity: String(quantity)
      };
      if (await this.postCartAction('update', params)) return true;
      if (item && item.key && await this.postCartEdit(item.key, quantity)) return true;
      if (cart.source === 'local' && item) {
        item.quantity = quantity;
        this.saveLocalCart(cart.items);
        return true;
      }
      return false;
    }

    async removeCartItem(action) {
      const cart = await this.getCart();
      const item = this.findCartItem(cart, action);
      const params = {
        product_id: action.product_id || (item && item.product_id) || '',
        key: (item && item.key) || ''
      };
      if (await this.postCartAction('remove', params)) return true;
      if (item && item.key && await this.postCartRemove(item.key)) return true;
      if (cart.source === 'local' && item) {
        this.saveLocalCart(cart.items.filter((line) => line !== item));
        return true;
      }
      return false;
    }

    async clearCart() {
      if (await this.postCartAction('clear', {})) return true;
      const cart = await this.getCart();
      if (cart.items.length) {
        const results = await Promise.all(cart.items.map((item) => item.key ? this.postCartRemove(item.key) : Promise.resolve(false)));
        if (results.some(Boolean)) return true;
      }
      const localCart = this.loadLocalCart();
      if (localCart.items.length) {
        this.saveLocalCart([]);
        return true;
      }
      return false;
    }

    async applyCoupon(action) {
      const code = action.code || '';
      if (!code) return false;
      if (await this.postCartAction('coupon', { code: code })) return true;
      if (!this.config.couponRoute) return false;
      try {
        const response = await fetch(this.buildShopUrl(this.config.couponRoute).toString(), {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ coupon: code, code: code })
        });
        const payload = await response.json().catch(() => ({}));
        return response.ok && payload.error === undefined && payload.status !== 'error';
      } catch (error) {
        console.warn('OpenCart coupon request failed:', error);
        return false;
      }
    }

    async postCartEdit(key, quantity) {
      const body = new URLSearchParams({ key: key, quantity: String(quantity) });
      body.set(`quantity[${key}]`, String(quantity));
      return this.postFirstSuccessful(['index.php?route=checkout/cart.edit', 'index.php?route=checkout/cart/edit'], body);
    }

    async postCartRemove(key) {
      return this.postFirstSuccessful(
        ['index.php?route=checkout/cart.remove', 'index.php?route=checkout/cart/remove'],
        new URLSearchParams({ key: key })
      );
    }

    async postFirstSuccessful(routes, body) {
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
          console.warn('OpenCart request failed:', error);
        }
      }
      return false;
    }

    async addToCart(productId, quantity) {
      const routes = [
        'index.php?route=checkout/cart.add',
        'index.php?route=checkout/cart/add'
      ];
      const body = new URLSearchParams({
        product_id: productId,
        quantity: String(quantity || 1)
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

  function boot() {
    window.AIShoppingAssistant = AIShoppingAssistant;
    new AIShoppingAssistant(window.AI_SHOPPING_ASSIST_CONFIG || {});
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
