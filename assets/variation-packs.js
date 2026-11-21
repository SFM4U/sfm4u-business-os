/* WooCommerce sends eligible pack options with the selected variation. */
jQuery(function ($) {
    $('.variations_form').each(function () {
        const form = this;
        const wrapper = form.querySelector('[data-sfmwh-variation-packs]');
        if (!wrapper) return;
        const select = wrapper.querySelector('select');
        const clear = () => {
            select.replaceChildren();
            select.disabled = true;
            wrapper.hidden = true;
        };
        $(form).on('found_variation', function (event, variation) {
            clear();
            const options = variation.sfmwh_pack_options;
            if (!options || options.length < 2) return;
            options.forEach(function (option) {
                select.add(new Option(option.label, option.id));
            });
            select.disabled = false;
            wrapper.hidden = false;
        }).on('reset_data hide_variation', clear);
    });
});
