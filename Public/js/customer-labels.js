(function () {
    function updateLabels() {
        var template = document.getElementById('nostr-customer-labels');
        if (!template) return;
        var labels = new Map();
        template.content.querySelectorAll('[data-nostr-pubkey]').forEach(function (label) {
            labels.set(label.dataset.nostrPubkey, label.textContent);
        });
        document.querySelectorAll('.nostr-device-label').forEach(function (label) {
            if (labels.has(label.dataset.nostrPubkey)) {
                label.textContent = labels.get(label.dataset.nostrPubkey);
            }
        });
    }
    document.addEventListener('customapp:loaded', updateLabels);
    // Each page, also one opened with wire:navigate.
    document.addEventListener('livewire:navigated', updateLabels);
    document.addEventListener('tallport:conversation-opened', function () {
        // After the customer's panel has rendered.
        setTimeout(updateLabels, 0);
    });
})();
