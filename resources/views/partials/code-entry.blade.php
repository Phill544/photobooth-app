{{-- The join form — six tiles over a hidden input — shared by the home page
     and the unknown-code page, so both check a code the same way. Styles live
     with the rest of the design system in partials/theme.blade.php. --}}
{{-- action + method matter: without JavaScript the submit used to re-GET the
     page it was already on, which ignores ?code=, so the guest saw an empty
     field and nothing moved — and on the 404 a retry re-served the same 404. --}}
<form id="join" class="join-form" action="/join" method="get">
    <label class="sr-only" for="code">Event code</label>
    <div class="code-entry">
        <input id="code" name="code" maxlength="6" autocapitalize="characters"
               autocomplete="off" spellcheck="false" placeholder="CODE"
               aria-describedby="code-error" required>
        <div class="tiles" aria-hidden="true"></div>
    </div>
    <p class="error" id="code-error" role="alert" hidden>Codes are six characters.</p>
    <button class="btn--hero">Enter the booth</button>
</form>

<script>
    (() => {
        const form = document.querySelector('#join');
        const input = form.querySelector('#code');
        const entry = form.querySelector('.code-entry');
        const tiles = form.querySelector('.tiles');
        const error = form.querySelector('#code-error');

        // Swap the plain input for the tile display; only runs when JS does.
        for (let i = 0; i < input.maxLength; i++) tiles.appendChild(document.createElement('span'));
        entry.classList.add('tiled');

        const paint = () => {
            const code = input.value.toUpperCase();
            [...tiles.children].forEach((tile, index) => {
                tile.textContent = code[index] ?? '';
                tile.className = 'tile' + (code[index] ? '' : index === code.length ? ' caret' : ' empty');
            });
        };
        input.addEventListener('input', () => {
            error.hidden = true;
            paint();
        });
        paint();
        input.focus();

        // A short code can only ever 404, so it gets answered here instead of
        // costing the guest a page load to be told the same thing.
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const code = input.value.trim();

            if (code.length !== input.maxLength) {
                error.hidden = false;
                input.focus();
                return;
            }

            location.href = `/e/${encodeURIComponent(code)}`;
        });
    })();
</script>
