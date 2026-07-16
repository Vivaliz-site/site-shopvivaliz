        </main>
    </div>

    <div id="sv-toast" class="sv-toast"></div>
    <script>
        window.svToast = function (message, isError) {
            var el = document.getElementById('sv-toast');
            el.textContent = message;
            el.className = 'sv-toast show' + (isError ? ' error' : '');
            clearTimeout(window.__svToastTimer);
            window.__svToastTimer = setTimeout(function () {
                el.className = 'sv-toast';
            }, 3200);
        };
    </script>
</body>
</html>
