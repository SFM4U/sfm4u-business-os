(() => {
    let fingerprint;
    let pending;
    const refresh = () => {
        const card = document.querySelector('[data-sfmwh-profit]');
        const store = window.wp?.data?.select('wc/store/cart');
        const items = store?.getCartData()?.items;
        if (!card || !Array.isArray(items)) return;
        const next = JSON.stringify(items.map(({ key, quantity, totals }) => [key, quantity, totals?.line_total]));
        if (fingerprint === undefined) {
            fingerprint = next;
            return;
        }
        if (fingerprint === next) return;
        fingerprint = next;
        pending?.abort();
        const controller = new AbortController();
        pending = controller;
        fetch(card.dataset.sfmwhProfit, { signal: controller.signal, credentials: 'same-origin', cache: 'no-store' })
            .then(response => response.json())
            .then(result => {
                if (result.success && pending === controller) {
                    card.outerHTML = result.data;
                }
            }).catch(() => {});
    };
    window.wp?.data?.subscribe(refresh);
    refresh();
})();
