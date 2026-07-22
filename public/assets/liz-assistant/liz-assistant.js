(function() {
    const API = "/api/agent/liz-intelligence.php";

    function init() {
        if (!document.getElementById("sv-liz-panel")) {
            createPanel();
        }
        attachListeners();
    }

    function createPanel() {
        const panel = document.createElement("div");
        panel.id = "sv-liz-panel";
        panel.innerHTML = `
            <div class="sv-liz-header">
                <h2>🤖 Assistente Liz</h2>
                <button onclick="document.getElementById('sv-liz-panel').classList.remove('open');document.body.classList.remove('sv-liz-is-open');">×</button>
            </div>
            <div class="sv-liz-messages" id="sv-liz-messages">
                <div class="sv-liz-message bot">
                    <div class="sv-liz-message-content">Olá! Eu sou a Liz. Como posso ajudá-lo?</div>
                </div>
            </div>
            <div class="sv-liz-input-area">
                <input type="text" id="sv-liz-input" placeholder="Digite sua pergunta...">
                <button id="sv-liz-send">Enviar</button>
            </div>
        `;
        document.body.appendChild(panel);
    }

    function attachListeners() {
        const input = document.getElementById("sv-liz-input");
        const sendBtn = document.getElementById("sv-liz-send");

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

        const userMsg = document.createElement("div");
        userMsg.className = "sv-liz-message user";
        userMsg.innerHTML = '<div class="sv-liz-message-content">' + escapeHtml(message) + '</div>';
        messagesDiv.appendChild(userMsg);

        input.value = "";
        input.focus();
        sendBtn.disabled = true;

        const loadingMsg = document.createElement("div");
        loadingMsg.className = "sv-liz-message bot";
        loadingMsg.innerHTML = '<div class="sv-liz-message-content">Enviando...</div>';
        messagesDiv.appendChild(loadingMsg);
        messagesDiv.scrollTop = messagesDiv.scrollHeight;

        fetch(API, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ message: message })
        })
        .then(response => response.json())
        .then(data => {
            loadingMsg.remove();
            const answer = data.answer || "Erro ao processar.";
            const botMsg = document.createElement("div");
            botMsg.className = "sv-liz-message bot";
            botMsg.innerHTML = '<div class="sv-liz-message-content">' + escapeHtml(answer) + '</div>';
            messagesDiv.appendChild(botMsg);
            messagesDiv.scrollTop = messagesDiv.scrollHeight;
            sendBtn.disabled = false;
        })
        .catch(() => {
            loadingMsg.remove();
            const errorMsg = document.createElement("div");
            errorMsg.className = "sv-liz-message bot";
            errorMsg.innerHTML = '<div class="sv-liz-message-content">Erro. Tente novamente.</div>';
            messagesDiv.appendChild(errorMsg);
            sendBtn.disabled = false;
        });
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
