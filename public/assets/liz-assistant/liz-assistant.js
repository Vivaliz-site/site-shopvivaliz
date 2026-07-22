(function() {
    const API = "/api/agent/liz-intelligence.php";
    const STORAGE_KEY_HISTORY = "sv_liz_chat_history_v2";
    const STORAGE_KEY_USER_ID = "sv_liz_user_id_v2";

    function getUserId() {
        let id = localStorage.getItem(STORAGE_KEY_USER_ID);
        if (!id) {
            id = "user_" + Math.random().toString(36).substring(2, 11) + "_" + Date.now();
            localStorage.setItem(STORAGE_KEY_USER_ID, id);
        }
        return id;
    }

    function loadHistory() {
        try {
            return JSON.parse(localStorage.getItem(STORAGE_KEY_HISTORY) || "[]");
        } catch(e) {
            return [];
        }
    }

    function saveHistory(history) {
        try {
            localStorage.setItem(STORAGE_KEY_HISTORY, JSON.stringify(history.slice(-30)));
        } catch(e) {}
    }

    function init() {
        if (!document.getElementById("sv-liz-panel")) {
            createPanel();
        }
        renderStoredHistory();
        attachListeners();
    }

    function createPanel() {
        const panel = document.createElement("div");
        panel.id = "sv-liz-panel";
        panel.innerHTML = `
            <div class="sv-liz-header" style="display:flex; justify-content:space-between; align-items:center; padding: 12px 16px;">
                <h2 style="font-size:16px; font-weight:700; margin:0; display:flex; align-items:center; gap:6px;">🤖 Assistente Liz</h2>
                <div style="display:flex; gap:8px; align-items:center;">
                    <button id="sv-liz-clear" title="Limpar conversa" style="background:none; border:none; color:#cbd5e1; cursor:pointer; font-size:12px; padding:2px 6px;">🗑️ Limpar</button>
                    <button onclick="document.getElementById('sv-liz-panel').classList.remove('open');document.body.classList.remove('sv-liz-is-open');" style="background:none; border:none; color:#fff; font-size:20px; cursor:pointer; line-height:1;">×</button>
                </div>
            </div>
            <div class="sv-liz-messages" id="sv-liz-messages">
                <div class="sv-liz-message bot">
                    <div class="sv-liz-message-content">Olá! Eu sou a Liz, assistente da ShopVivaliz. Como posso te ajudar hoje?</div>
                </div>
            </div>
            <div class="sv-liz-input-area">
                <input type="text" id="sv-liz-input" placeholder="Digite sua pergunta...">
                <button id="sv-liz-send">Enviar</button>
            </div>
        `;
        document.body.appendChild(panel);
    }

    function renderStoredHistory() {
        const messagesDiv = document.getElementById("sv-liz-messages");
        if (!messagesDiv) return;

        const history = loadHistory();
        if (history.length > 0) {
            messagesDiv.innerHTML = "";
            history.forEach(item => {
                const msgDiv = document.createElement("div");
                msgDiv.className = "sv-liz-message " + (item.sender === "user" ? "user" : "bot");
                msgDiv.innerHTML = '<div class="sv-liz-message-content">' + formatText(item.text) + '</div>';
                messagesDiv.appendChild(msgDiv);
            });
            messagesDiv.scrollTop = messagesDiv.scrollHeight;
        }
    }

    function attachListeners() {
        const input = document.getElementById("sv-liz-input");
        const sendBtn = document.getElementById("sv-liz-send");
        const clearBtn = document.getElementById("sv-liz-clear");

        if (clearBtn) {
            clearBtn.addEventListener("click", () => {
                localStorage.removeItem(STORAGE_KEY_HISTORY);
                const messagesDiv = document.getElementById("sv-liz-messages");
                if (messagesDiv) {
                    messagesDiv.innerHTML = `
                        <div class="sv-liz-message bot">
                            <div class="sv-liz-message-content">Conversa reiniciada. Como posso ajudar?</div>
                        </div>
                    `;
                }
            });
        }

        if (!input || !sendBtn) return;

        sendBtn.addEventListener("click", () => send());
        input.addEventListener("keypress", (e) => {
            if (e.key === "Enter") {
                send();
            }
        });
    }

    function send() {
        const input = document.getElementById("sv-liz-input");
        const sendBtn = document.getElementById("sv-liz-send");
        const messagesDiv = document.getElementById("sv-liz-messages");

        if (!input || !messagesDiv || !sendBtn) return;

        const message = input.value.trim();
        if (!message) return;

        const history = loadHistory();
        history.push({ sender: "user", text: message });
        saveHistory(history);

        const userMsg = document.createElement("div");
        userMsg.className = "sv-liz-message user";
        userMsg.innerHTML = '<div class="sv-liz-message-content">' + escapeHtml(message) + '</div>';
        messagesDiv.appendChild(userMsg);

        input.value = "";
        input.focus();
        sendBtn.disabled = true;

        const loadingMsg = document.createElement("div");
        loadingMsg.className = "sv-liz-message bot";
        loadingMsg.innerHTML = '<div class="sv-liz-message-content">Consultando dados... ⏳</div>';
        messagesDiv.appendChild(loadingMsg);
        messagesDiv.scrollTop = messagesDiv.scrollHeight;

        fetch(API, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                message: message,
                user_id: getUserId()
            })
        })
        .then(response => response.json())
        .then(data => {
            loadingMsg.remove();
            const answer = data.answer || "Estou à disposição para ajudar na ShopVivaliz!";

            const updatedHistory = loadHistory();
            updatedHistory.push({ sender: "bot", text: answer });
            saveHistory(updatedHistory);

            const botMsg = document.createElement("div");
            botMsg.className = "sv-liz-message bot";
            botMsg.innerHTML = '<div class="sv-liz-message-content">' + formatText(answer) + '</div>';
            messagesDiv.appendChild(botMsg);
            messagesDiv.scrollTop = messagesDiv.scrollHeight;
            sendBtn.disabled = false;
        })
        .catch(() => {
            loadingMsg.remove();
            const errorMsg = document.createElement("div");
            errorMsg.className = "sv-liz-message bot";
            errorMsg.innerHTML = '<div class="sv-liz-message-content">Tive um breve imprevisto técnico. Você também pode nos chamar pelo WhatsApp (37) 99937-4112.</div>';
            messagesDiv.appendChild(errorMsg);
            sendBtn.disabled = false;
        });
    }

    function formatText(text) {
        if (!text) return "";
        let escaped = escapeHtml(text);
        // Converter markdown links [Texto](url) para HTML <a href="url">Texto</a>
        escaped = escaped.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function(match, label, url) {
            const cleanUrl = url.trim();
            const isExternal = cleanUrl.startsWith("http");
            return '<a href="' + cleanUrl + '" ' + (isExternal ? 'target="_blank" rel="noopener"' : '') + ' style="color:#3b82f6; text-decoration:underline; font-weight:600;">' + label + '</a>';
        });
        // Quebras de linha
        return escaped.replace(/\n/g, "<br>");
    }

    function escapeHtml(text) {
        const div = document.createElement("div");
        div.textContent = text;
        return div.innerHTML;
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
