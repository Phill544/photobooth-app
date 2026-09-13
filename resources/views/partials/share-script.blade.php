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
            // The label is read once, before the first tap can change it. Read
            // inside the handler, a second tap within the window captured
            // "Copied!" as the word to go back to, and the button wore that
            // until the page reloaded. The timer is tracked for the same reason:
            // the first tap's must not land in the middle of the second's.
            const label = copy.textContent;
            let restore;
            copy.addEventListener('click', async () => {
                try {
                    await navigator.clipboard?.writeText(copy.dataset.copy);
                } catch {
                    return; // clipboard blocked (some webviews) — the URL is shown in .link-chip anyway
                }
                copy.textContent = 'Copied!';
                copy.classList.add('copied');
                clearTimeout(restore);
                restore = setTimeout(() => { copy.textContent = label; copy.classList.remove('copied'); }, 1600);
            });
        }
    })();
</script>
