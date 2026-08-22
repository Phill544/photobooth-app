<script>
    // Progressive invite: native share sheet where available, copy-link everywhere else.
    // The .link-chip always shows the URL, so this works even with JS disabled.
    (function () {
        for (const btn of document.querySelectorAll('.share-btn')) {
            if (!navigator.share) { btn.hidden = true; continue; }
            btn.addEventListener('click', () => {
                navigator.share({ title: btn.dataset.shareTitle, url: btn.dataset.shareUrl }).catch(() => {});
            });
        }
        for (const copy of document.querySelectorAll('.share-copy')) {
            copy.addEventListener('click', async () => {
                await navigator.clipboard?.writeText(copy.dataset.copy);
                const label = copy.textContent;
                copy.textContent = 'Copied!';
                copy.classList.add('copied');
                setTimeout(() => { copy.textContent = label; copy.classList.remove('copied'); }, 1600);
            });
        }
    })();
</script>
