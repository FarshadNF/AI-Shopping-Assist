(function () {
  class RokoAssistant {
    constructor(customConfig) {
      if (window.__ROKO_LOADED__) return;
      window.__ROKO_LOADED__ = true;

      this.defaults = {
        apiBase: '',
        chatRoute: '',
        cartRoute: 'index.php?route=checkout/cart',
        productRoute: 'index.php?route=product/product',
        searchRoute: 'index.php?route=product/search',
        cartInfoRoute: 'index.php?route=extension/module/roko/getCart',
        cartActionRoute: 'index.php?route=extension/module/roko/cartAction',
        checkoutRoute: 'index.php?route=checkout/checkout',
        couponRoute: 'index.php?route=extension/total/coupon/coupon',
        invoiceRoute: 'index.php?route=extension/module/roko/sendInvoice',
        redirectLogRoute: 'index.php?route=extension/module/roko/logRedirect',
        redirectUtm: '',
        showNextQuestionSuggestions: true,
        showBlogSuggestions: true,
        showCategorySuggestions: true,
        showProductSuggestions: true,
        title: 'ROKO',
        buttonText: 'ROKO',
        avatarUrl: '',
        iconUrl: '',
        redirectDelayMs: 700,
        maxHistoryMessages: 80,
        maxConversations: 30,
        welcomeMessage: 'Hi, I am ROKO. Tell me what you need and I will help you find the right product.',
        starterSuggestions: [
          {
            title: 'Product Specifications',
            text: 'What are the specifications of your best-selling product?'
          },
          {
            title: 'Product Recommendation',
            text: 'Can you recommend a product for my needs?'
          },
          {
            title: 'Compare Products',
            text: 'Can you compare two popular products?'
          },
          {
            title: 'Accessory Suggestion',
            text: 'Can you recommend a useful accessory?'
          }
        ],
        blogBubbleEnabled: true,
        blogBubbleMessage: 'Want a quick summary? Chat with ROKO now!',
        blogBubbleDelayMs: 1500
      };

      this.config = Object.assign({}, this.defaults, customConfig || {});
      this.config.apiBase = String(this.config.apiBase || '').replace(/\/+$/, '');

      this.storagePrefix = 'roko:' + location.origin;
      this.keys = {
        conversationId: this.storagePrefix + ':conversation-id',
        conversations: this.storagePrefix + ':conversations',
        chatHistory: this.storagePrefix + ':chat-history',
        legacyMigrated: this.storagePrefix + ':legacy-history-migrated',
        cartSnapshot: this.storagePrefix + ':cart-snapshot',
        blogBubbleDismissed: this.storagePrefix + ':blog-bubble-dismissed'
      };
      this.currentConversationId = this.initializeConversations();

      this.init();
    }

    init() {
      this.renderDOM();
      this.cacheElements();
      this.renderConversationList();
      this.restoreChatHistory();
      this.renderStarterSuggestions();
      this.bindEvents();
      this.initContextBubble();
    }

    renderDOM() {
      this.root = document.createElement('div');
      this.root.id = 'roko-widget';
      this.root.innerHTML = `
        <div class="aisa-panel" hidden aria-live="polite">
          <div class="aisa-head">
            <div class="aisa-head-main">
              <img class="aisa-avatar" alt="" hidden />
              <div class="aisa-head-copy">
                <div class="aisa-title"></div>
                <span class="aisa-status">Ready to help</span>
              </div>
            </div>
            <div class="aisa-head-actions">
              <button type="button" class="aisa-history-toggle" aria-label="Conversations" title="Conversations">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 6h11M8 12h11M8 18h7M4 6h.01M4 12h.01M4 18h.01"/></svg>
              </button>
              <button type="button" class="aisa-new-chat" aria-label="New chat" title="New chat">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
              </button>
              <button type="button" class="aisa-close" aria-label="Close" title="Close">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
              </button>
            </div>
          </div>
          <div class="aisa-conversations" hidden>
            <div class="aisa-conversations-head">
              <span>Conversations</span>
            </div>
            <div class="aisa-conversation-list"></div>
          </div>
          <div class="aisa-messages"></div>
          <form class="aisa-form">
            <input class="aisa-input" type="text" autocomplete="off" placeholder="Ask about products..." required />
            <button class="aisa-send" type="submit" aria-label="Send message" title="Send message">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 14-7-4 14-3-6-7-1Z"/><path d="m12 13 7-8"/></svg>
              <span>Send</span>
            </button>
          </form>
        </div>
        <div class="aisa-launcher">
          <div class="aisa-context-bubble" hidden role="status" aria-live="polite">
            <button type="button" class="aisa-context-bubble-close" aria-label="Dismiss notification">&times;</button>
            <p class="aisa-context-bubble-text"></p>
            <span class="aisa-context-bubble-tail" aria-hidden="true"><i></i><i></i></span>
          </div>
          <button type="button" class="aisa-toggle">
            <img class="aisa-toggle-avatar" alt="" hidden />
            <span class="aisa-toggle-text"></span>
          </button>
        </div>
      `;
      document.body.appendChild(this.root);
    }

    cacheElements() {
      this.panel = this.root.querySelector('.aisa-panel');
      this.launcher = this.root.querySelector('.aisa-launcher');
      this.contextBubble = this.root.querySelector('.aisa-context-bubble');
      this.contextBubbleText = this.root.querySelector('.aisa-context-bubble-text');
      this.contextBubbleClose = this.root.querySelector('.aisa-context-bubble-close');
      this.toggleBtn = this.root.querySelector('.aisa-toggle');
      this.closeBtn = this.root.querySelector('.aisa-close');
      this.historyToggleBtn = this.root.querySelector('.aisa-history-toggle');
      this.newChatBtn = this.root.querySelector('.aisa-new-chat');
      this.conversationTray = this.root.querySelector('.aisa-conversations');
      this.conversationList = this.root.querySelector('.aisa-conversation-list');
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
      this.toggleBtn.setAttribute('aria-label', this.config.buttonText || this.defaults.buttonText);
      this.toggleBtn.setAttribute('title', this.config.buttonText || this.defaults.buttonText);
      this.applyAvatar();
    }

    applyAvatar() {
      const avatarUrl = String(this.config.avatarUrl || '');
      const iconUrl = String(this.config.iconUrl || avatarUrl);
      if (!avatarUrl && !iconUrl) return;

      if (avatarUrl) {
        this.avatar.src = avatarUrl;
        this.avatar.hidden = false;
      }

      if (iconUrl) {
        this.toggleAvatar.src = iconUrl;
        this.toggleAvatar.hidden = false;
      }
    }

    isBlogPage() {
      const path = String(location.pathname || '').toLowerCase();
      return /(^|\/)(blog|article|news)(\/|$)/.test(path) || path.includes('rockford-blog');
    }

    getPageContentType() {
      const path = String(location.pathname || '').toLowerCase();
      const search = String(location.search || '').toLowerCase();

      if (this.isBlogPage()) {
        return 'blog';
      }

      if (search.includes('product_id=') || /(^|\/)(product|products)(\/|$)/.test(path)) {
        return 'product';
      }

      if (search.includes('path=') || search.includes('category_id=') || /(^|\/)(category|categories)(\/|$)/.test(path)) {
        return 'category';
      }

      return 'page';
    }

    getPageContext() {
      const cacheKey = String(location.pathname || '') + String(location.search || '');
      if (!this._pageContextCache || this._pageContextCacheKey !== cacheKey) {
        this._pageContextCacheKey = cacheKey;
        this._pageContextCache = this.buildPageContext();
      }
      return this._pageContextCache;
    }

    buildPageContext() {
      return {
        url: window.location.href,
        title: document.title || '',
        content_type: this.getPageContentType(),
        description: this.extractMetaDescription(),
        image: this.extractPageImage(),
        content: this.extractPageText()
      };
    }

    extractMetaDescription() {
      const meta = document.querySelector('meta[name="description"], meta[property="og:description"]');
      return meta ? String(meta.getAttribute('content') || '').trim() : '';
    }

    extractPageImage() {
      const meta = document.querySelector('meta[property="og:image"], meta[name="twitter:image"]');
      if (meta) {
        const content = String(meta.getAttribute('content') || '').trim();
        if (content) return content;
      }

      const image = document.querySelector('article img, #content img, main img, .post-image img, .product-thumb img');
      return image ? String(image.getAttribute('src') || image.src || '').trim() : '';
    }

    extractPageText() {
      const selectors = [
        'article',
        '.post-content',
        '.blog-content',
        '.entry-content',
        '.article-content',
        '#content .content',
        '#content',
        'main',
        '.product-description',
        '#product-description',
        '#product'
      ];

      let root = null;
      for (const selector of selectors) {
        const element = document.querySelector(selector);
        if (element && this.getVisibleTextLength(element) > 120) {
          root = element;
          break;
        }
      }

      if (!root) {
        root = document.querySelector('#content') || document.querySelector('main') || document.body;
      }

      const clone = root.cloneNode(true);
      clone.querySelectorAll(
        '#roko-widget, script, style, noscript, nav, header, footer, iframe, svg, .breadcrumb, .breadcrumbs, .aisa-panel'
      ).forEach((element) => element.remove());

      return this.normalizePageText(clone.innerText || clone.textContent || '');
    }

    getVisibleTextLength(element) {
      const text = String(element && (element.innerText || element.textContent) || '').replace(/\s+/g, ' ').trim();
      return text.length;
    }

    normalizePageText(text) {
      return String(text || '').replace(/\s+/g, ' ').trim().slice(0, 4500);
    }

    isBlogBubbleDismissed() {
      try {
        const dismissed = JSON.parse(localStorage.getItem(this.keys.blogBubbleDismissed) || '{}');
        return dismissed[location.pathname] === true;
      } catch (error) {
        return false;
      }
    }

    dismissBlogBubble() {
      try {
        const dismissed = JSON.parse(localStorage.getItem(this.keys.blogBubbleDismissed) || '{}');
        dismissed[location.pathname] = true;
        localStorage.setItem(this.keys.blogBubbleDismissed, JSON.stringify(dismissed));
      } catch (error) {
        // Ignore storage failures.
      }
      this.hideContextBubble();
    }

    getBlogBubbleMessage() {
      const configured = String(this.config.blogBubbleMessage || this.defaults.blogBubbleMessage || '').trim();
      const title = String(document.title || '').replace(/\s*[\-|–|—|•|·|::|:].*$/, '').trim();
      if (!title || title.length < 8) {
        return configured;
      }
      const shortTitle = title.length > 42 ? title.slice(0, 39).trim() + '...' : title;
      return 'Want a quick summary of "' + shortTitle + '"? Ask ROKO now!';
    }

    initContextBubble() {
      if (!this.contextBubble || this.config.blogBubbleEnabled === false || !this.isBlogPage() || this.isBlogBubbleDismissed()) {
        return;
      }

      this.contextBubbleText.textContent = this.getBlogBubbleMessage();

      this.contextBubbleClose.addEventListener('click', (event) => {
        event.stopPropagation();
        this.dismissBlogBubble();
      });

      this.contextBubble.addEventListener('click', () => {
        this.dismissBlogBubble();
        this.openPanel();
      });

      const delay = Number(this.config.blogBubbleDelayMs ?? this.defaults.blogBubbleDelayMs) || 0;
      this._blogBubbleTimer = window.setTimeout(() => {
        if (!this.panel.hidden) return;
        this.showContextBubble();
      }, Math.max(0, delay));
    }

    showContextBubble() {
      if (!this.contextBubble || this.panel.hidden === false) return;
      this.contextBubble.hidden = false;
      this.launcher.classList.add('has-context-bubble');
    }

    hideContextBubble() {
      if (!this.contextBubble) return;
      this.contextBubble.hidden = true;
      this.launcher.classList.remove('has-context-bubble');
    }

    bindEvents() {
      this.toggleBtn.addEventListener('click', () => {
        if (this.panel.hidden) {
          this.openPanel();
        } else {
          this.closePanel();
        }
      });

      this.closeBtn.addEventListener('click', () => {
        this.closePanel();
      });

      this.historyToggleBtn.addEventListener('click', () => {
        this.renderConversationList();
        this.conversationTray.hidden = !this.conversationTray.hidden;
      });

      this.newChatBtn.addEventListener('click', () => {
        this.startNewConversation();
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

    openPanel() {
      this.panel.hidden = false;
      this.launcher.hidden = true;
      if (this.contextBubble && !this.contextBubble.hidden) {
        this.dismissBlogBubble();
      } else {
        this.hideContextBubble();
      }
      this.root.classList.add('is-open');
      this.toggleBtn.setAttribute('aria-expanded', 'true');
      this.scrollMessagesToBottom();
      this.input.focus();
    }

    closePanel() {
      this.panel.hidden = true;
      this.launcher.hidden = false;
      this.root.classList.remove('is-open');
      this.toggleBtn.setAttribute('aria-expanded', 'false');
      this.conversationTray.hidden = true;
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

    initializeConversations() {
      this.migrateLegacyHistory();

      let conversations = this.loadConversations();
      let activeId = localStorage.getItem(this.keys.conversationId) || '';

      if (activeId && !conversations.some((conversation) => conversation.id === activeId)) {
        conversations.unshift(this.createConversationMeta(activeId, 'New conversation'));
        this.saveConversations(conversations);
      }

      conversations = this.loadConversations();

      if (!activeId || !conversations.some((conversation) => conversation.id === activeId)) {
        activeId = conversations.length ? conversations[0].id : this.createConversationRecord('New conversation').id;
      }

      this.setActiveConversationId(activeId);
      return activeId;
    }

    readJson(key, fallback) {
      try {
        const value = JSON.parse(localStorage.getItem(key) || '');
        return value === null ? fallback : value;
      } catch (error) {
        return fallback;
      }
    }

    generateConversationId() {
      return 'web-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
    }

    getHistoryKey(conversationId) {
      return this.storagePrefix + ':conversation:' + encodeURIComponent(String(conversationId || 'default')) + ':messages';
    }

    getActiveConversationId() {
      if (this.currentConversationId) return this.currentConversationId;

      const storedId = localStorage.getItem(this.keys.conversationId);
      if (storedId) {
        this.currentConversationId = storedId;
        return storedId;
      }

      const conversation = this.createConversationRecord('New conversation');
      this.setActiveConversationId(conversation.id);
      return conversation.id;
    }

    setActiveConversationId(conversationId) {
      this.currentConversationId = String(conversationId || '');
      if (this.currentConversationId) {
        localStorage.setItem(this.keys.conversationId, this.currentConversationId);
      }
    }

    createConversationMeta(conversationId, title) {
      const now = Date.now();
      return {
        id: String(conversationId || this.generateConversationId()),
        title: this.shortenText(title || 'New conversation', 46),
        preview: '',
        createdAt: now,
        updatedAt: now
      };
    }

    createConversationRecord(title) {
      const conversation = this.createConversationMeta(this.generateConversationId(), title || 'New conversation');
      const conversations = this.loadConversations().filter((item) => item.id !== conversation.id);
      conversations.unshift(conversation);
      this.saveConversations(conversations);
      return conversation;
    }

    loadConversations() {
      const parsed = this.readJson(this.keys.conversations, []);
      if (!Array.isArray(parsed)) return [];

      return parsed.map((conversation) => {
        if (!conversation || typeof conversation !== 'object') return null;
        const id = String(conversation.id || '').trim();
        if (!id) return null;

        return {
          id: id,
          title: this.shortenText(conversation.title || 'New conversation', 46),
          preview: this.shortenText(conversation.preview || '', 70),
          createdAt: Number(conversation.createdAt || Date.now()),
          updatedAt: Number(conversation.updatedAt || conversation.createdAt || Date.now())
        };
      }).filter(Boolean).sort((left, right) => right.updatedAt - left.updatedAt);
    }

    saveConversations(conversations) {
      const seen = {};
      const limit = Math.max(1, Number(this.config.maxConversations) || 30);
      const normalized = [];

      conversations.forEach((conversation) => {
        if (!conversation || !conversation.id || seen[conversation.id]) return;
        seen[conversation.id] = true;
        normalized.push(conversation);
      });

      localStorage.setItem(this.keys.conversations, JSON.stringify(normalized.slice(0, limit)));
    }

    migrateLegacyHistory() {
      if (localStorage.getItem(this.keys.legacyMigrated) === '1') return;

      const legacyMessages = this.sanitizeMessages(this.readJson(this.keys.chatHistory, []));
      if (legacyMessages.length) {
        const legacyId = localStorage.getItem(this.keys.conversationId) || this.generateConversationId();
        const legacyHistoryKey = this.getHistoryKey(legacyId);

        if (!localStorage.getItem(legacyHistoryKey)) {
          localStorage.setItem(legacyHistoryKey, JSON.stringify(legacyMessages));
        }

        const conversations = this.loadConversations();
        if (!conversations.some((conversation) => conversation.id === legacyId)) {
          const title = this.deriveConversationTitle(legacyMessages) || 'Previous conversation';
          const updatedAt = legacyMessages[legacyMessages.length - 1].createdAt || Date.now();
          conversations.unshift(Object.assign(this.createConversationMeta(legacyId, title), {
            preview: this.shortenText(legacyMessages[legacyMessages.length - 1].text || '', 70),
            updatedAt: updatedAt
          }));
          this.saveConversations(conversations);
        }
      }

      localStorage.setItem(this.keys.legacyMigrated, '1');
    }

    sanitizeMessages(messages) {
      if (!Array.isArray(messages)) return [];

      return messages.filter((message) => {
        return message
          && (message.role === 'user' || message.role === 'bot')
          && (
            (typeof message.text === 'string' && message.text.trim())
            || (Array.isArray(message.suggestions) && message.suggestions.length)
            || (Array.isArray(message.products) && message.products.length)
          );
      }).map((message) => ({
        role: message.role === 'user' ? 'user' : 'bot',
        text: String(message.text || ''),
        suggestions: this.normalizeSuggestions(message.suggestions || []),
        products: this.normalizeProducts(message.products || []),
        createdAt: Number(message.createdAt || Date.now())
      }));
    }

    loadMessagesForConversation(conversationId) {
      return this.sanitizeMessages(this.readJson(this.getHistoryKey(conversationId), []));
    }

    startNewConversation() {
      if (this.sendBtn.disabled) return;
      const conversation = this.createConversationRecord('New conversation');
      this.switchConversation(conversation.id);
    }

    switchConversation(conversationId) {
      if (this.sendBtn.disabled || !conversationId) return;

      this.setActiveConversationId(conversationId);
      this.messagesContainer.innerHTML = '';
      this.restoreChatHistory();
      this.renderStarterSuggestions();
      this.renderConversationList();
      this.conversationTray.hidden = true;
      this.scrollMessagesToBottom();
      this.input.focus();
    }

    renderConversationList() {
      if (!this.conversationList) return;

      const activeId = this.getActiveConversationId();
      const conversations = this.loadConversations();
      this.conversationList.innerHTML = '';

      conversations.forEach((conversation) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'aisa-conversation-item';
        if (conversation.id === activeId) button.classList.add('is-active');

        const title = document.createElement('span');
        title.className = 'aisa-conversation-title';
        title.textContent = conversation.title || 'New conversation';
        button.appendChild(title);

        const meta = document.createElement('span');
        meta.className = 'aisa-conversation-meta';
        meta.textContent = this.formatConversationTime(conversation.updatedAt);
        button.appendChild(meta);

        if (conversation.preview) {
          const preview = document.createElement('span');
          preview.className = 'aisa-conversation-preview';
          preview.textContent = conversation.preview;
          button.appendChild(preview);
        }

        button.addEventListener('click', () => this.switchConversation(conversation.id));
        this.conversationList.appendChild(button);
      });
    }

    updateConversationMeta(conversationId, message) {
      const conversations = this.loadConversations();
      let conversation = conversations.find((item) => item.id === conversationId);

      if (!conversation) {
        conversation = this.createConversationMeta(conversationId, 'New conversation');
        conversations.unshift(conversation);
      }

      const text = String((message && message.text) || '').replace(/\s+/g, ' ').trim();
      if (message && message.role === 'user' && text && (!conversation.title || conversation.title === 'New conversation')) {
        conversation.title = this.shortenText(text, 46);
      }
      if (text) conversation.preview = this.shortenText(text, 70);
      conversation.updatedAt = Number((message && message.createdAt) || Date.now());

      this.saveConversations(conversations);
      this.renderConversationList();
    }

    deriveConversationTitle(messages) {
      const firstUserMessage = (messages || []).find((message) => {
        return message && message.role === 'user' && String(message.text || '').trim();
      });

      return firstUserMessage ? this.shortenText(firstUserMessage.text, 46) : '';
    }

    shortenText(text, limit) {
      const value = String(text || '').replace(/\s+/g, ' ').trim();
      const max = Math.max(1, Number(limit) || 46);
      return value.length > max ? value.slice(0, max - 3).trim() + '...' : value;
    }

    formatConversationTime(timestamp) {
      const value = Number(timestamp || Date.now());
      try {
        return new Intl.DateTimeFormat(undefined, {
          month: 'short',
          day: 'numeric',
          hour: '2-digit',
          minute: '2-digit'
        }).format(new Date(value));
      } catch (error) {
        return '';
      }
    }

    setStatus(text) {
      this.statusLabel.textContent = text;
      this.statusLabel.classList.toggle('is-busy', /typing|working|loading/i.test(String(text || '')));
    }

    showTypingIndicator() {
      this.hideTypingIndicator();

      const item = document.createElement('div');
      item.className = 'aisa-message bot aisa-typing';
      item.setAttribute('role', 'status');
      item.setAttribute('aria-live', 'polite');
      item.setAttribute('aria-label', 'ROKO is typing');

      const dots = document.createElement('span');
      dots.className = 'aisa-typing-dots';
      dots.setAttribute('aria-hidden', 'true');

      for (let index = 0; index < 3; index += 1) {
        const dot = document.createElement('span');
        dot.className = 'aisa-typing-dot';
        dots.appendChild(dot);
      }

      item.appendChild(dots);
      this.messagesContainer.appendChild(item);
      this.typingIndicator = item;
      this.scrollMessagesToBottom();
    }

    hideTypingIndicator() {
      if (this.typingIndicator && this.typingIndicator.parentNode) {
        this.typingIndicator.parentNode.removeChild(this.typingIndicator);
      }
      this.typingIndicator = null;
    }

    addMessage(role, text, persist, extras) {
      const cleanedText = this.stripAction(text);
      const messageExtras = extras || {};
      const products = this.normalizeProducts(messageExtras.products);
      const suggestions = this.normalizeSuggestions(messageExtras.suggestions);
      if (!cleanedText && !products.length && !suggestions.length) return;

      const item = document.createElement('div');
      item.className = 'aisa-message ' + (role === 'user' ? 'user' : 'bot');
      item.dir = this.isRtlMessage(cleanedText) ? 'rtl' : 'ltr';
      if (cleanedText) {
        item.textContent = cleanedText;
      }

      this.messagesContainer.appendChild(item);

      if (role !== 'user') {
        this.renderProductCards(products);
        this.renderSuggestions(suggestions);
      }

      this.scrollMessagesToBottom();

      if (persist !== false) {
        this.rememberChatMessage(role, cleanedText, {
          products: products,
          suggestions: suggestions
        });
      }
    }

    renderStarterSuggestions() {
      if (this.loadChatHistory().length) return;
      this.addMessage('bot', this.config.welcomeMessage || this.defaults.welcomeMessage, false);
      this.renderSuggestions(this.config.starterSuggestions || []);
      this.scrollMessagesToBottom();
    }

    loadChatHistory() {
      return this.loadMessagesForConversation(this.getActiveConversationId());
    }

    saveChatHistory(messages) {
      const limit = Math.max(1, Number(this.config.maxHistoryMessages) || 80);
      const trimmed = messages.slice(-limit);
      localStorage.setItem(this.getHistoryKey(this.getActiveConversationId()), JSON.stringify(trimmed));
      return trimmed;
    }

    rememberChatMessage(role, text, extras) {
      try {
        const conversationId = this.getActiveConversationId();
        const messages = this.loadChatHistory();
        const message = {
          role: role === 'user' ? 'user' : 'bot',
          text: String(text || ''),
          suggestions: this.normalizeSuggestions((extras && extras.suggestions) || []),
          products: this.normalizeProducts((extras && extras.products) || []),
          createdAt: Date.now()
        };
        messages.push(message);
        this.saveChatHistory(messages);
        this.updateConversationMeta(conversationId, message);
      } catch (error) {
        console.warn('ROKO could not save chat history:', error);
      }
    }

    restoreChatHistory() {
      const messages = this.loadChatHistory();
      messages.forEach((message) => {
        this.addMessage(message.role, message.text, false, {
          suggestions: message.suggestions || [],
          products: message.products || []
        });
      });
      this.scrollMessagesToBottom();
    }

    normalizeSuggestions(suggestions) {
      if (this.config.showNextQuestionSuggestions === false) return [];
      if (!Array.isArray(suggestions)) return [];
      return suggestions.map((suggestion) => {
        if (typeof suggestion === 'string') {
          return { title: '', text: suggestion.trim() };
        }
        if (!suggestion || typeof suggestion !== 'object') return null;
        return {
          title: String(suggestion.title || suggestion.label || '').trim(),
          text: String(suggestion.text || suggestion.message || suggestion.prompt || '').trim()
        };
      }).filter((suggestion) => suggestion && suggestion.text).slice(0, 6);
    }

    normalizeProducts(products) {
      if (!Array.isArray(products)) return [];
      return products.map((product) => {
        if (!product || typeof product !== 'object') return null;
        const rawStock = product.stock === null || product.stock === undefined || product.stock === '' ? null : Number(product.stock || product.quantity || 0);
        const contentType = String(product.content_type || product.type || (product.product_id || product.id ? 'product' : 'page')).trim().toLowerCase();
        return {
          product_id: String(product.product_id || product.id || ''),
          name: String(product.name || product.product_name || product.title || '').trim(),
          product_url: String(product.product_url || product.url || product.href || '').trim(),
          content_type: contentType,
          price: String(product.price || '').trim(),
          stock: rawStock,
          image: String(product.image || '').trim(),
          category: String(product.category || '').trim(),
          summary: String(product.summary || product.reason || product.description || '').trim(),
          attributes: product.attributes && typeof product.attributes === 'object' ? product.attributes : {}
        };
      }).filter((product) => product && product.name && this.isSuggestionCardEnabled(product.content_type)).slice(0, 4);
    }

    isSuggestionCardEnabled(contentType) {
      const type = String(contentType || 'product').trim().toLowerCase();
      if (['blog', 'article', 'news', 'page'].includes(type)) {
        return this.config.showBlogSuggestions !== false;
      }
      if (type === 'category') {
        return this.config.showCategorySuggestions !== false;
      }
      return this.config.showProductSuggestions !== false;
    }

    renderSuggestions(suggestions) {
      const normalized = this.normalizeSuggestions(suggestions);
      if (!normalized.length) return;

      const wrap = document.createElement('div');
      wrap.className = 'aisa-suggestions';

      normalized.forEach((suggestion) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = suggestion.title ? 'aisa-suggestion-card' : 'aisa-suggestion-chip';
        button.dir = this.isRtlMessage(suggestion.title + ' ' + suggestion.text) ? 'rtl' : 'ltr';

        if (suggestion.title) {
          const title = document.createElement('span');
          title.className = 'aisa-suggestion-title';
          title.textContent = suggestion.title;
          button.appendChild(title);
        }

        const text = document.createElement('span');
        text.className = 'aisa-suggestion-text';
        text.textContent = suggestion.text;
        button.appendChild(text);

        button.addEventListener('click', () => {
          this.submitSuggestion(suggestion.text);
        });

        wrap.appendChild(button);
      });

      this.messagesContainer.appendChild(wrap);
    }

    async submitSuggestion(text) {
      const value = String(text || '').trim();
      if (!value || this.sendBtn.disabled) return;
      this.input.value = '';
      this.addMessage('user', value);
      await this.askAssistant(value);
    }

    renderProductCards(products) {
      const normalized = this.normalizeProducts(products);
      if (!normalized.length) return;

      const wrap = document.createElement('div');
      wrap.className = 'aisa-product-cards';

      normalized.forEach((product) => {
        wrap.appendChild(this.createProductCard(product));
      });

      this.messagesContainer.appendChild(wrap);
    }

    getContentTypeLabel(contentType) {
      const type = String(contentType || 'page').trim().toLowerCase();
      if (type === 'blog' || type === 'article' || type === 'news') return 'Article';
      if (type === 'product') return 'Product';
      if (type === 'category') return 'Category';
      return type.charAt(0).toUpperCase() + type.slice(1);
    }

    getContentActionLabel() {
      return 'Learn more';
    }

    createProductCard(product) {
      const contentType = String(product.content_type || 'product').trim().toLowerCase();
      const isProduct = contentType === 'product' && Boolean(product.product_id);
      const isTextLink = !isProduct && !product.image;
      const card = document.createElement('article');
      card.className = 'aisa-product-card ' + (isProduct ? 'is-product' : 'is-article') + (isTextLink ? ' is-text-link' : ' has-media');
      card.dir = this.isRtlMessage(product.name + ' ' + String(product.summary || '')) ? 'rtl' : 'ltr';

      const row = document.createElement('div');
      row.className = 'aisa-product-row' + (isTextLink ? ' is-text-only' : '');

      if (!isTextLink) {
        const media = document.createElement('div');
        media.className = 'aisa-product-media';

        if (product.image) {
          const image = document.createElement('img');
          image.className = 'aisa-product-image';
          image.src = product.image;
          image.alt = product.name;
          image.loading = 'lazy';
          media.appendChild(image);
        } else {
          const placeholder = document.createElement('div');
          placeholder.className = 'aisa-product-media-placeholder';
          placeholder.textContent = 'P';
          media.appendChild(placeholder);
        }

        row.appendChild(media);
      }

      const main = document.createElement('div');
      main.className = 'aisa-product-main';

      const title = document.createElement(isTextLink ? 'button' : 'h3');
      title.className = 'aisa-product-title' + (isTextLink ? ' aisa-product-title-link' : '');
      title.textContent = product.name;
      title.title = product.name;
      if (isTextLink) {
        title.type = 'button';
        title.addEventListener('click', () => this.redirectToProduct(product));
      }
      main.appendChild(title);

      if (!isProduct && !isTextLink) {
        const type = document.createElement('span');
        type.className = 'aisa-product-type';
        type.textContent = this.getContentTypeLabel(contentType);
        main.appendChild(type);
      } else if (product.price) {
        const price = document.createElement('span');
        price.className = 'aisa-product-price';
        price.textContent = product.price;
        main.appendChild(price);
      }

      const summaryText = String(product.summary || (!isProduct ? product.category : '')).trim();
      if (summaryText) {
        const summary = document.createElement('p');
        summary.className = 'aisa-product-summary';
        summary.textContent = summaryText;
        main.appendChild(summary);
      }

      row.appendChild(main);
      card.appendChild(row);

      if (!isTextLink) {
        const actions = document.createElement('div');
        actions.className = 'aisa-product-actions';

        const learn = document.createElement('button');
        learn.type = 'button';
        learn.className = 'aisa-product-action primary';
        learn.textContent = this.getContentActionLabel();
        learn.addEventListener('click', () => this.redirectToProduct(product));
        actions.appendChild(learn);

        if (isProduct && product.product_id) {
          const enquire = document.createElement('button');
          enquire.type = 'button';
          enquire.className = 'aisa-product-action secondary';
          enquire.textContent = 'Enquire';
          enquire.addEventListener('click', () => this.startProductEnquiry(product));
          actions.appendChild(enquire);
        }

        card.appendChild(actions);
      }

      return card;
    }

    startProductEnquiry(product) {
      const item = product && typeof product === 'object' ? product : {};
      const productName = String(item.product_name || item.name || '').replace(/\s+/g, ' ').trim();
      if (!productName) return;

      const previousForm = this.messagesContainer.querySelector('.aisa-enquiry-card');
      if (previousForm) previousForm.remove();

      this.addMessage('bot', `Please complete your enquiry details for ${productName}.`, true);

      const form = document.createElement('form');
      form.className = 'aisa-enquiry-card';
      form.dir = 'ltr';

      const title = document.createElement('strong');
      title.className = 'aisa-enquiry-title';
      title.textContent = 'Product enquiry';
      form.appendChild(title);

      const productLabel = document.createElement('div');
      productLabel.className = 'aisa-enquiry-product';
      productLabel.textContent = productName;
      form.appendChild(productLabel);

      const fields = [
        { name: 'qty', label: 'QTY', type: 'number', value: this.getRequestedQuantity(item), min: '1' },
        { name: 'name', label: 'Name', type: 'text' },
        { name: 'company', label: 'Company', type: 'text', required: false },
        { name: 'contact_number', label: 'Contact Number', type: 'tel' },
        { name: 'email', label: 'Email', type: 'email' },
        { name: 'delivery_location', label: 'Delivery Location', type: 'text' }
      ];

      fields.forEach((field) => {
        const label = document.createElement('label');
        label.className = 'aisa-enquiry-field';

        const caption = document.createElement('span');
        caption.textContent = field.label;
        label.appendChild(caption);

        const input = document.createElement('input');
        input.name = field.name;
        input.type = field.type;
        input.required = field.required !== false;
        input.autocomplete = field.name === 'email' ? 'email' : (field.name === 'contact_number' ? 'tel' : 'off');
        if (field.value !== undefined) input.value = String(field.value);
        if (field.min) input.min = field.min;
        label.appendChild(input);
        form.appendChild(label);
      });

      const submit = document.createElement('button');
      submit.type = 'submit';
      submit.className = 'aisa-enquiry-submit';
      submit.textContent = 'Submit enquiry';
      form.appendChild(submit);

      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (this.sendBtn.disabled) return;

        const values = new FormData(form);
        const qty = String(values.get('qty') || '1').trim();
        const name = String(values.get('name') || '').trim();
        const company = String(values.get('company') || '').trim() || 'Individual';
        const contactNumber = String(values.get('contact_number') || '').trim();
        const email = String(values.get('email') || '').trim();
        const deliveryLocation = String(values.get('delivery_location') || '').trim();
        const payload = [
          `Product Name: ${productName}`,
          `QTY: ${qty}`,
          `Name: ${name}`,
          `Company: ${company}`,
          `Contact Number: ${contactNumber}`,
          `Email: ${email}`,
          `Delivery Location: ${deliveryLocation}`
        ].join('\n');

        form.querySelectorAll('input, button').forEach((control) => {
          control.disabled = true;
        });
        this.addMessage('user', `Enquiry submitted for ${productName}.`, true);
        form.remove();
        await this.askAssistant(payload);
      });

      this.messagesContainer.appendChild(form);
      this.scrollMessagesToBottom();
      const firstInput = form.querySelector('input[name="name"]');
      if (firstInput) firstInput.focus();
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
      return String(text || '').replace(/\[ACTION:\s*(?:ADD_TO_CART|ENQUIRE):[^\]]+\]/gi, '').trim();
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
      this.setStatus('Typing...');
      this.showTypingIndicator();

      try {
        const body = {
          message: message,
          page_context: this.getPageContext()
        };
        const conversationId = this.getActiveConversationId();
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
        this.hideTypingIndicator();

        if (!response.ok || data.status !== 'success') {
          if (data.conversation_id) {
            this.setActiveConversationId(data.conversation_id);
          }
          if (data.reply) {
            this.addMessage('bot', data.reply, true, {
              suggestions: data.suggestions || [],
              products: data.products || []
            });
            return;
          }
          throw new Error(data.reply || 'Chat request failed');
        }

        if (data.conversation_id) {
          this.setActiveConversationId(data.conversation_id);
        }

        this.addMessage('bot', data.reply, true, {
          suggestions: data.suggestions || [],
          products: data.products || []
        });

        for (const action of this.getActions(data)) {
          await this.handleAction(action, message, data);
        }
      } catch (error) {
        console.error('ROKO error:', error);
        this.hideTypingIndicator();
        this.addMessage('bot', this.localizedConnectionError(message));
      } finally {
        this.hideTypingIndicator();
        this.setStatus('Ready to help');
        this.sendBtn.disabled = false;
        this.input.focus();
      }
    }

    async handleAction(action, message, data) {
      const rawActionType = action.type || (action.product_id ? 'enquire' : '');
      const actionType = rawActionType === 'add_to_cart' ? 'enquire' : rawActionType;

      if (actionType === 'enquire' && action.product_id) {
        this.startProductEnquiry(action);
        return;
      }

      if (actionType === 'show_cart') {
        await this.showCart(message);
        return;
      }

      if (actionType === 'redirect_to_cart') {
        this.redirectToRoute(this.config.cartRoute, 'redirect_to_cart');
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
        if (!updated) this.redirectToRoute(this.config.cartRoute, 'update_cart_item');
        return;
      }

      if (actionType === 'remove_from_cart') {
        const removed = await this.removeCartItem(action);
        this.addMessage('bot', removed ? this.localizedRemovedMessage(message) : this.localizedActionFailedMessage(message));
        if (!removed) this.redirectToRoute(this.config.cartRoute, 'remove_from_cart');
        return;
      }

      if (actionType === 'clear_cart') {
        const cleared = await this.clearCart();
        this.addMessage('bot', cleared ? this.localizedClearedMessage(message) : this.localizedActionFailedMessage(message));
        if (!cleared) this.redirectToRoute(this.config.cartRoute, 'clear_cart');
        return;
      }

      if (actionType === 'apply_coupon') {
        const applied = await this.applyCoupon(action);
        this.addMessage('bot', applied ? this.localizedCouponMessage(message) : this.localizedActionFailedMessage(message));
        if (!applied) this.redirectToRoute(this.config.cartRoute, 'apply_coupon');
        return;
      }

      if (actionType === 'redirect_to_checkout') {
        this.redirectToRoute(this.config.checkoutRoute, 'redirect_to_checkout');
        return;
      }

      if (actionType === 'send_invoice') {
        const sent = await this.requestInvoice(action, data);
        if (sent) {
          this.addMessage('bot', this.localizedInvoiceSentMessage(message));
          return;
        }
        this.addMessage('bot', this.localizedInvoiceFallbackMessage(message));
        this.redirectToRoute(this.config.checkoutRoute, 'send_invoice');
      }
    }

    redirectToRoute(route, actionType = 'redirect_route') {
      if (!route) return;
      this.redirectToUrl(this.buildShopUrl(route).toString(), actionType);
    }

    redirectToPage(action) {
      const url = String(action.url || action.href || '');
      if (url) {
        this.redirectToUrl(url, 'redirect_to_page');
        return;
      }

      const route = String(action.route || '');
      if (route) {
        this.redirectToRoute(route, 'redirect_to_page');
      }
    }

    redirectToUrl(url, actionType = 'redirect') {
      if (!url) return;

      const sourceUrl = window.location.href;
      const destinationUrl = this.normalizeRedirectUrl(url);
      const destinationUrlUtm = this.appendRedirectUtm(destinationUrl);

      this.logRedirect({
        action_type: actionType,
        source_url: sourceUrl,
        destination_url: destinationUrl,
        destination_url_utm: destinationUrlUtm,
        utm_payload: String(this.config.redirectUtm || '').trim()
      });

      const delay = Number(this.config.redirectDelayMs || 0);
      window.setTimeout(() => {
        window.location.assign(destinationUrlUtm || destinationUrl);
      }, Number.isFinite(delay) ? delay : 0);
    }

    redirectToProduct(action) {
      const productUrl = String(action.product_url || action.url || action.href || '');
      if (productUrl) {
        this.redirectToUrl(productUrl, 'redirect_to_product');
        return;
      }

      if (action.product_id) {
        const url = this.buildShopUrl(this.config.productRoute);
        url.searchParams.set('product_id', String(action.product_id));
        this.redirectToUrl(url.toString(), 'redirect_to_product');
        return;
      }

      if (action.product_name) {
        const url = this.buildShopUrl(this.config.searchRoute);
        url.searchParams.set('search', String(action.product_name));
        this.redirectToUrl(url.toString(), 'redirect_to_product');
      }
    }

    normalizeRedirectUrl(url) {
      try {
        return new URL(url, this.getShopBaseUrl()).toString();
      } catch (error) {
        return String(url || '');
      }
    }

    appendRedirectUtm(url) {
      const utm = String(this.config.redirectUtm || '').trim();
      if (!utm || !url) {
        return url;
      }

      try {
        const parsed = new URL(url, this.getShopBaseUrl());
        const pairs = utm.split('&');

        pairs.forEach((pair) => {
          const trimmed = String(pair || '').trim();
          if (!trimmed) {
            return;
          }

          const separatorIndex = trimmed.indexOf('=');
          const key = decodeURIComponent((separatorIndex >= 0 ? trimmed.slice(0, separatorIndex) : trimmed).trim());
          const value = decodeURIComponent((separatorIndex >= 0 ? trimmed.slice(separatorIndex + 1) : '').trim());

          if (!key || parsed.searchParams.has(key)) {
            return;
          }

          parsed.searchParams.set(key, value);
        });

        return parsed.toString();
      } catch (error) {
        return url;
      }
    }

    logRedirect(payload) {
      if (!this.config.redirectLogRoute) {
        return;
      }

      const body = new URLSearchParams({
        conversation_id: this.currentConversationId || '',
        action_type: payload.action_type || 'redirect',
        source_url: payload.source_url || '',
        destination_url: payload.destination_url || '',
        destination_url_utm: payload.destination_url_utm || '',
        utm_payload: payload.utm_payload || ''
      });

      try {
        const requestUrl = this.buildShopUrl(this.config.redirectLogRoute).toString();
        if (typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function') {
          const blob = new Blob([body.toString()], { type: 'application/x-www-form-urlencoded' });
          const sent = navigator.sendBeacon(requestUrl, blob);
          if (sent) {
            return;
          }
        }

        fetch(requestUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body,
          keepalive: true
        }).catch(() => {});
      } catch (error) {
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
        this.redirectToRoute(this.config.cartRoute, 'show_cart');
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
          name: action.product_name || action.name || productId,
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
    window.RokoAssistant = RokoAssistant;
    new RokoAssistant(window.ROKO_CONFIG || {});
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
