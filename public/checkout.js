/* Razorpay Standard Checkout for Sylius (buildwebproject/sylius-razorpay-plugin). */
(() => {
    const root = document.getElementById('razorpay-checkout');
    if (!root || root.dataset.ready) return;
    root.dataset.ready = '1';

    const pay = document.getElementById('razorpay-pay');
    const check = document.getElementById('razorpay-check');
    const status = document.getElementById('razorpay-status');
    const msg = (key) => root.dataset['msg' + key];
    let busy = false;
    let loading = null;

    const lock = (value) => { busy = value; pay.disabled = value; check.disabled = value; };
    const say = (text) => { status.textContent = text; };

    const post = async (action, fields = {}) => {
        const response = await fetch(root.dataset.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            body: new URLSearchParams({ _token: root.dataset.token, action, ...fields }),
        });
        let data = {};
        try { data = await response.json(); } catch (e) { /* not JSON */ }
        if (!response.ok) throw new Error(data.error || msg('NotConfirmed'));
        return data;
    };

    const show = (data) => {
        if (data.redirect) {
            say(msg('Paid'));
            window.location.assign(data.redirect);
            return;
        }
        if (data.result === 'review') {
            pay.hidden = true;
            say(msg('Review'));
            return;
        }
        say(msg('NotConfirmed'));
    };

    const loadScript = () => {
        if (window.Razorpay) return Promise.resolve();
        if (loading) return loading;
        loading = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'https://checkout.razorpay.com/v1/checkout.js';
            script.onload = () => (window.Razorpay ? resolve() : reject(new Error(msg('Script'))));
            script.onerror = () => { script.remove(); loading = null; reject(new Error(msg('Script'))); };
            document.head.appendChild(script);
        });
        return loading;
    };

    const open = async () => {
        if (busy) return;
        lock(true);
        say(msg('Opening'));
        try {
            await loadScript();
            const { checkout } = await post('start');
            const razorpay = new window.Razorpay({
                ...checkout,
                handler: async (result) => {
                    lock(true);
                    say(msg('Confirming'));
                    try {
                        show(await post('verify', {
                            razorpay_order_id: result.razorpay_order_id,
                            razorpay_payment_id: result.razorpay_payment_id,
                            razorpay_signature: result.razorpay_signature,
                        }));
                    } catch (e) {
                        say(e.message);
                    } finally {
                        lock(false);
                    }
                },
                modal: { ondismiss: () => { lock(false); say(msg('Closed')); } },
                retry: { enabled: true },
            });
            razorpay.on('payment.failed', () => say(msg('FailedAttempt')));
            razorpay.open();
        } catch (e) {
            say(e.message);
            lock(false);
        }
    };

    pay.addEventListener('click', open);
    check.addEventListener('click', async () => {
        if (busy) return;
        lock(true);
        try { show(await post('status')); } catch (e) { say(e.message); } finally { lock(false); }
    });

    if (root.dataset.result === 'ready' || root.dataset.result === 'pending') open();
})();
