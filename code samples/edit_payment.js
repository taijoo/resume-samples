
/***** CODE SAMPLE: A profanely complex support script for intuitively applying invoice payments *****/


var money_formatter = new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 2
});

function format_money(number) {
    return money_formatter.format(number);
}

function interpret_money(amount) {
    return parseFloat(('' + amount).replaceAll(',', '').replace('$', ''));
}

function monetize(amount) {
    return parseFloat(roundTo(interpret_money(amount), 2));
}

function roundTo(num, precision) {
    return (+(Math.round(+(num + 'e' + precision)) + 'e' + -precision)).toFixed(precision);
}

var tableKey = 'client-payment.table';
var filters = { };

function get_hash() {
    return hash_get(tableKey);
}

function load_filters(state) {
    var set_filters = { };
    for(var i in FILTERS)
        set_filters[i] = typeof state != 'undefined' && state && typeof state[i] != 'undefined' ? state[i] : 0;
    return(set_filters);
}

function uit_extra_data() {
    var data = { payment_invoices: 1 };
    if(payment_id)
        data.payment_id = payment_id;
    for(var i in FILTERS)
        data[i] = typeof filters[i] != 'undefined' ? filters[i] : 0;
    data['include_cms'] = 0;
    if($('#client_id').val())
        data['client_id'] = $('#client_id').val();
    else data['client_id'] = -1;
    return data;
}

set_filters(load_filters(pref(tableKey)));

var columns = [
    { title: 'ID', id: 'id', width: 70 },
    { title: 'Invoice ID', id: 'invoice_id', width: 100 },
    { title: 'Status', id: 'status', width: 100 },
    { title: 'Total Amount', id: 'invoiced', width: 100 },
    { title: 'Paid', id: 'paid', width: 100 },
    { title: 'Balance', id: 'balance', width: 100 },
    { title: 'Due', id: 'due', width: 120 },
    { title: 'Payment Application', id: 'payment_applied', minWidth: 100, filter: false, sortable: false },
];

var uit  = 0;
var guit = uit = new uitable('#uitable', {
    prefKey: tableKey,
    version: 0.4,
    ajaxData: uit_extra_data,
    columns: columns,
    sort: [ 'id', 'dsc' ],
    ajaxEndpoint: 'uitable_invoices_load',
    selectable: 10000,
    dblclickRow: function(row) {
        if(typeof row.id != 'undefined' && row.id)
            window.open(location.pathname + '?mod=accountants&act=edit_invoice&id=' + row.id);
    },
    no_dblclickLink: true,
    block_top_offset: true
});

var invoices = 0;
var explain_for = '';
var explain_max = '';

function update_totals() {
    var amount = interpret_money($('input[name="amount"]').val());
    if(isNaN(amount))
        amount = 0;
    amount = amount.toFixed(2);

    var applied = 0;
    for(var i in payment_application)
        applied += monetize(payment_application[i].amount);
    applied = applied.toFixed(2);
    var remaining = (amount - applied).toFixed(2);

    $('#i_applied').val(format_money(applied));
    $('#i_remaining').val(format_money(remaining));

    if(applied > 0) {
        if(!$('#client_id').hasClass('pseudo-disabled')) {
            $('#client_id').addClass('pseudo-disabled');
            var client_id = $('#client_id').val();
            $('#client_id option').each(function() {
                $(this).removeClass('disabled');
                if($(this).val() != client_id)
                    $(this).addClass('disabled');
            });
        }
    }
    else {
        if($('#client_id').hasClass('pseudo-disabled')) {
            $('#client_id').removeClass('pseudo-disabled');
            $('#client_id option').each(function() {
                $(this).removeClass('disabled');
            });
        }
    }
    if(monetize(applied) > monetize(amount)) {
        $('body').addClass('overapplied');
    }
    else {
        $('body').removeClass('overapplied');
    }
}
update_totals();

$('input[name="amount"]').on('change', function(e) {
    if($(this).val())
        $(this).val(interpret_money($(this).val()));
    update_totals();
});

function sync_apply_button() {
    if($('div.apply-payment input[name="apply_for"]').val()) {
        $('button.apply-payment').removeClass('disabled');
    }
    else $('button.apply-payment').addClass('disabled');
}

$('div.apply-payment input[name="apply_for"]').on('keyup', function(e) {
    sync_apply_button();
});
$('div.apply-payment input[name="apply_for"]').on('change', function(e) {
    sync_apply_button();
});

function get_all_applied_for_invoice(invoice_id, return_full) {
    var payments = [ ];
    for(var i in payment_application)
        if(payment_application[i].invoice_id == invoice_id)
            payments.push(return_full ? payment_application[i] : monetize(payment_application[i].amount));
    if(payments.length)
        return payments;
    return 0;
}

function get_applied_for_invoice(invoice_id, return_full) {
    for(var i in payment_application)
        if(payment_application[i].invoice_id == invoice_id)
            return return_full ? payment_application[i] : monetize(payment_application[i].amount);
    return 0;
}

function get_fully_applied_for_invoice(invoice_id, return_full) {
    for(var i in payment_application)
        if(payment_application[i].invoice_id == invoice_id && payment_application[i].id && payment_application[i].id != '0')
            return return_full ? payment_application[i] : monetize(payment_application[i].amount);
    return 0;
}

function get_newly_applied_for_invoice(invoice_id, return_full) {
    for(var i in payment_application)
        if(payment_application[i].invoice_id == invoice_id && (!payment_application[i].id || payment_application[i].id == '0'))
            return return_full ? payment_application[i] : monetize(payment_application[i].amount);
    return 0;
}

function set_newly_applied_for_invoice(invoice_id, amount) {
    amount = monetize(amount);
    var found = 0;
    for(var i in payment_application) {
        if(payment_application[i].invoice_id == invoice_id && (!payment_application[i].id || payment_application[i].id == '0')) {
            debug('setting ' + stringify(payment_application[i]) + ' amount to ' + amount, 1);
            found = 1;
            if(amount)
                payment_application[i].amount = original_amount;
            else payment_application.splice(i, 1);
            break;
        }
    }
    if(!found && amount)
        payment_application.push({ id: 0, amount: amount, invoice_id: invoice_id });
    show_applied_for_invoice(invoice_id);
    $('input[type="submit"]').addClass('flash');
    debug('set newly complete, applied now: ' + stringify(payment_application), 1);
}

function set_applied_for_invoice(invoice_id, amount, adjust) {
    if(adjust)
        return set_newly_applied_for_invoice(invoice_id, amount);
    amount = monetize(amount);
    var found = 0;
    for(var i in payment_application) {
        if(payment_application[i].invoice_id == invoice_id && payment_application[i].id && payment_application[i].id != '0') {  // already-applied
            // no need to change anything - just adjust the amount with the already-paid amount
            var original_amount = amount;
            if(amount > payment_application[i].amount)
                amount -= payment_application[i].amount;
            else amount = -(payment_application[i].amount - amount);
            amount = monetize(amount);
            debug('amount adjusted from ' + original_amount + ' to ' + amount + ' for already-applied', 1);
        }
    }
    for(var i in payment_application) {
        if(payment_application[i].invoice_id == invoice_id && (!payment_application[i].id || payment_application[i].id == '0')) {  // newly-applied
            debug('setting ' + stringify(payment_application[i]) + ' amount to ' + amount, 1);
            found = 1;
            if(amount) {
                var original_amount = amount;
                amount -= payment_application[i].amount;
                amount = monetize(amount);
                payment_application[i].amount = original_amount;
            }
            else payment_application.splice(i, 1);
            break;
        }
    }
    if(!found && amount)
        payment_application.push({ id: 0, amount: amount, invoice_id: invoice_id });
    show_applied_for_invoice(invoice_id);
    $('input[type="submit"]').addClass('flash');
    debug('set complete, applied now: ' + stringify(payment_application), 1);
}

function show_applied_for_invoice(invoice_id) {
    var html = '';
    var amount = 0;
    if(amount = get_fully_applied_for_invoice(invoice_id))
        html += (html.length ? ' ' : '') + "<span class='previously-applied'>$" + format_money(amount) + '</span>';
    if(amount = get_newly_applied_for_invoice(invoice_id)) {
        if(amount > 0)
            html += (html.length ? ' ' : '') + "<span class='newly-applied'>+ $" + format_money(amount) + "</span>";
        else html += (html.length ? ' ' : '') + "<span class='newly-applied-negative'>- $" + format_money(Math.abs(amount)) + "</span>";
    }
    if(!html.length)
        html = '&nbsp;';
    $('#uitable row[data-id="' + invoice_id + '"] cell[pos=' + applied_column + ']').html(html);
}

var debug_level = 2;  // 1 for most detailed, 3 for main actions - 0 to disable

function debug(str, level) {
    if(!level)
        level = 3;
    if(debug_level && level >= debug_level)
        console.log(str);
}

function stringify(value) {
    return JSON.stringify(value);
}

/*

apply_payment(applying_amount);

If <applying_amount> is -1, then it will be changed to the maximum allowable (already-applied amounts, newly-applied amounts, and the remaining credit).
This function makes three passes over the selected invoice list, trying to apply the <applying_amount> by priority and in order until that amount runs out.
The first pass applies the <applying_amount> to the invoices with already-applied amounts from this payment - i.e., it tries to maintain the status quo.  If you've paid <x> on the first invoice, then <x> will be allocated from the <applying_amount>, then it continues to the next invoice.
The second pass applies the <applying_amount> to the invoices with newly-applied (applied but not yet saved) amounts from this payment.
The third pass applies the remaining <applying_amount> to the invoices in order selected, until <applying_amount> runs out.

- does not account for multiple people editing same payment at same time
- could implement handshaking / inline-validate-fail system

*/

function apply_payment(applying_amount, apply_max) {
    if(applying_amount < 0) {
        notify("You cannot apply a negative amount.", true);
        return;
    }
    var warnings = [ ];
    var invoice_ids = $('div.apply-payment-container').attr('data-ids');
    if(invoice_ids)
        invoice_ids = invoice_ids.split(',');
    var all_selected = invoice_ids.length == Object.keys(invoices).length;
    var application = { };
    var apply_target = 0;
    var balance = 0;
    var amount = 0;
    var already_applied = 0;
    var remaining = monetize($('#i_remaining').val());
    for(var i in invoice_ids) {
        var invoice_id = invoice_ids[i];
        application[invoice_id] = { 'applied': 0, 'apply': 0 };
        if(typeof(invoices[invoice_id]) != 'undefined') {
            var invoice = invoices[invoice_id];
            balance += monetize(invoice.balance);
            var applied_amount = 0;
            if(applied_payment = get_fully_applied_for_invoice(invoice_id, true)) {
                application[invoice.id][applied_payment.id ? 'applied' : 'apply'] = monetize(applied_payment.amount);
                already_applied += monetize(applied_payment.amount);
            }
            if(applied_payment = get_newly_applied_for_invoice(invoice_id, true)) {
                application[invoice.id][applied_payment.id ? 'applied' : 'apply'] = monetize(applied_payment.amount);
                already_applied += monetize(applied_payment.amount);
            }
        }
    }
    already_applied = monetize(already_applied);

    if(apply_max) {  // "max" to apply
        applying_amount = already_applied + remaining;
        applying_amount = monetize(applying_amount);
    }

    var old_applying = stringify(payment_application);
    debug('-- applying starts with amount ' + applying_amount + ' (' + already_applied + ' + ' + remaining + ') and payment_application: ' + old_applying, 3);

    // first pass - apply to already-applied invoices
    if(applying_amount >= 0) {
        for(var i in invoice_ids) {
            var invoice_id = invoice_ids[i];
            if(typeof(invoices[invoice_id]) != 'undefined') {
                var invoice = invoices[invoice_id];
                var invoice_applied = 0;
                var applied_payments = 0;
                if(applied_payments = get_all_applied_for_invoice(invoice_id, true)) {
                    debug('got all applied payments for ' + invoice_id + ': ' + stringify(applied_payments), 1);
                    for(var i in applied_payments) {
                        var applied_payment = applied_payments[i];
                        if(applied_payment.id) {  // already-applied amount
                            invoice_applied = monetize(applied_payment.amount);
                            debug('already-applied invoice_applied ' + applied_payment.amount + ' and ' + applying_amount, 1);
                            if(applying_amount >= invoice_applied) {  // no need to reduce
                                applying_amount -= invoice_applied;
                                applying_amount = monetize(applying_amount);
                                debug('stage 1-1: reduce applying_amount by ' + invoice_applied + ' to ' + applying_amount, 2);
                            }
                            else {  // reduce already-applied amount
                                var old_applied_amount = applied_payment.amount;
                                debug('stage 1: set ' + invoice_id + ' to ' + applying_amount, 2);
                                set_applied_for_invoice(invoice_id, applying_amount);

                                application[invoice_id].applied = applying_amount;
                                warnings.push("Warning: You are reducing the already-applied amount to Invoice " + invoice.invoice_id + " from $" + format_money(old_applied_amount) + " to $" + format_money(applying_amount));
                                applying_amount = 0;
                                debug('stage 1-2: reduce applying_amount to ' + applying_amount, 2);
                            }
                        }
                    }
                }
            }
        }
    }

    if(old_applying != stringify(payment_application)) debug('applying after stage 1 now: ' + (old_applying = stringify(payment_application)), 3);

    // next pass - apply to about-to-apply invoices
    if(applying_amount >= 0) {
        for(var i in invoice_ids) {
            var invoice_id = invoice_ids[i];
            if(typeof(invoices[invoice_id]) != 'undefined') {
                var invoice = invoices[invoice_id];
                var invoice_applied = 0;
                var applied_payments = 0;
                if(invoice_applied = get_newly_applied_for_invoice(invoice_id)) {
                    if(invoice_applied >= 0) {
                        if(applying_amount >= invoice_applied) {  // no need to reduce
                            applying_amount -= invoice_applied;
                            applying_amount = monetize(applying_amount);
                            debug('stage 2-1: reduce applying amount by ' + invoice_applied + ' to ' + applying_amount, 2);
                        }
                        else {  // reduce already-applied amount
                            var old_applied_amount = applied_payment.amount;
                            var already_applied = get_fully_applied_for_invoice(invoice_id);
                            debug('so stage 2 already_applied ' + already_applied + ' and applying amount ' + applying_amount, 1);
                            debug('stage 2: set ' + invoice_id + ' to ' + (applying_amount + already_applied), 3);
                            set_applied_for_invoice(invoice_id, monetize(applying_amount + already_applied));
                            application[invoice_id].apply = applying_amount;
//                                console.log("Warning: You are reducing the already-applied amount to Invoice " + invoice.invoice_id + " from $" + format_money(old_applied_amount) + " to $" + format_money(applying_amount));  // warn for reducing about-to-apply amounts
                            applying_amount = 0;
                            debug('stage 2-2: reduce applying amount to ' + applying_amount, 2);
                        }
                    }
                }
            }
        }
    }

    if(old_applying != stringify(payment_application)) debug('applying after stage 2 now: ' + (old_applying = stringify(payment_application)), 3);

    // last pass - apply to unapplied invoices
    if(applying_amount > 0) {
        for(var i in invoice_ids) {
            var invoice_id = invoice_ids[i];
            if(typeof(invoices[invoice_id]) != 'undefined') {
                var applied_to_invoice = 0;
                var invoice = invoices[invoice_id];
                var invoice_applied = 0;  // already applied
                var invoice_applying = 0;  // pending application
                var applied_payments = 0;
                if(applied_payments = get_all_applied_for_invoice(invoice_id, true)) {
                    for(var i in applied_payments) {
                        var applied_payment = applied_payments[i];
                        if(applied_payment.id && applied_payment.id != '0')
                            invoice_applied += monetize(applied_payment.amount);
                        else invoice_applying += monetize(applied_payment.amount);
                        applied_to_invoice = 1;
                    }
                    var invoice_remaining = monetize(invoice.balance) - invoice_applying;
                    debug('invoice remaining now ' + invoice_remaining + ', applying amount ' + applying_amount + ', applied ' + invoice_applied + ', applying ' + invoice_applying, 1);
                    debug('so applied ' + invoice_applied + ' and applying ' + invoice_applying + ' and balance ' + invoice.balance + ' and remaining ' + invoice_remaining, 1);
                    if(monetize(invoice_remaining) > 0) {
                        if(invoice_remaining <= applying_amount) {
                            debug('stage 3-1: set ' + invoice_id + ' to ' + (monetize(invoice_applying + invoice_applied) + invoice_remaining) + ' (' + monetize(invoice_applying + invoice_applied) + ' + ' + invoice_remaining + ')', 3);
                            set_applied_for_invoice(invoice_id, monetize(invoice_applying + invoice_applied) + invoice_remaining);
                            application[invoice.id].apply += invoice_remaining;
                            applying_amount -= invoice_remaining;
                            applying_amount = monetize(applying_amount);
                        }
                        else if(applying_amount) {
                            debug('stage 3-2: set ' + invoice_id + ' to ' + (monetize(invoice_applying + invoice_applied) + applying_amount) + ' (' + monetize(applied_payments[i].amount) + ' + ' + applying_amount + ')', 3);
                            set_applied_for_invoice(invoice_id, monetize(invoice_applying + invoice_applied) + applying_amount);
                            application[invoice.id].apply += applying_amount;
                            applying_amount = 0;
                        }
                    }
                }
                if(!applied_to_invoice) {
                    var invoice_remaining = monetize(invoice.balance);
                    if(invoice_remaining < applying_amount) {
                        application[invoice.id].apply += invoice_remaining;
                        applying_amount -= invoice_remaining;
                        applying_amount = monetize(applying_amount);
                        if(invoice_ids.length == 1 && !invoice_remaining)
                            warnings.push("There is no balance remaining on invoice #" + invoices[invoice_id].invoice_id + ".");
                    }
                    else if(applying_amount) {
                        application[invoice.id].apply += applying_amount;
                        applying_amount = 0;
                    }

                    if(application[invoice.id].apply) {
                        debug('not applied - setting for ' + invoice_id + ': ' + application[invoice.id].apply, 3);
                        set_applied_for_invoice(invoice_id, application[invoice.id].apply, true);
                    }
                }
            }
        }
    }

    if(old_applying != stringify(payment_application)) debug('applying after stage 3 now: ' + (old_applying = stringify(payment_application)), 3);
    debug('application ended up: ' + stringify(application), 1);

    show_apply_container(0);
    uit.select_none();
    update_totals();
    if(warnings.length)
        notify(warnings.join("<br>"), true);
}

$('div.apply-payment div.option-max').on('click', function(e) {
    if(!$(this).hasClass('disabled'))
        apply_payment(0, 1);
});

$('button.apply-payment').on('click', function(e) {
    if(!$(this).hasClass('disabled')) {
        var amount = interpret_money($('div.apply-payment input[name="apply_for"]').val());
        apply_payment(amount);
    }
});

$('button.cancel-apply-payment').on('click', function(e) {
    show_apply_container(0);
});

function show_apply_container(invoice_ids) {
    var apply_container = $('div.apply-payment-container');
    if(!invoice_ids) {
        apply_container.hide();
        return;
    }
    apply_container.show();
    var button = $('#apply_payment');
    var button_offset = button.offset();
    var module_offset = $('#module').offset();
    button_offset.left -= module_offset.left;
    button_offset.top -= module_offset.top;
    apply_container.css({ top: button_offset.top + button.outerHeight() - apply_container.outerHeight() - 2, left: button_offset.left + (button.outerWidth() / 2) - (apply_container.outerWidth() / 2) });

    var apply_target = 0;
    var invoice_names = [ ];
    var balance = 0;
    var amount = 0;
    var already_applied = 0;
    var remaining = interpret_money($('#i_remaining').val());
    var apply = $('div.apply-payment');
    $('div.apply-payment div.applied').hide();
    for(var i in invoice_ids) {
        var invoice_id = invoice_ids[i];
        if(typeof(invoices[invoice_id]) != 'undefined') {
            var invoice = invoices[invoice_id];
            invoice_names.push(invoice.invoice_id);
            balance += monetize(invoice.balance);
            if(newly_applied = get_newly_applied_for_invoice(invoice_id))
                balance -= newly_applied;
            if(applied_payments = get_all_applied_for_invoice(invoice_id)) {
                for(var j in applied_payments)
                    already_applied += applied_payments[j];
                $('span.already-applied', apply).html(format_money(already_applied));
                $('div.apply-payment div.applied').show();
            }
        }
    }
    already_applied = monetize(already_applied);
    balance = monetize(balance);
    if(invoices && invoice_ids.length == Object.keys(invoices).length)
        apply_target = "all applicable invoices";
    else if(invoice_ids.length >= 10) {
        apply_target = "invoices " + invoice_names.slice(0, 8).join(', ') + " and " + (invoice_names.length - 8) + " others";
    }
    else if(invoice_ids.length > 1)
        apply_target = "invoices " + invoice_names.join(', ');
    else apply_target = "invoice " + invoice_names[0];
    $('span.apply-target', apply).html(apply_target);
    apply_container.attr('data-ids', invoice_ids.join(','));

    $('div.apply-payment input[name="apply_for"]').val(already_applied ? interpret_money(already_applied) : '').trigger('change');
    explain_for = '';
    if(already_applied)
        explain_for = "Already applied " + format_money(already_applied);

    $('span.outstanding-balance', apply).html(format_money(balance));
    $('span.remaining-payment', apply).html(format_money(remaining));

    debug('show_apply: max = ' + remaining + ' < ' + balance + ' ? ' + remaining + ' + ' + already_applied + ' : ' + balance + ' + ' + already_applied, 2);
    var max = 0;
    if(remaining < balance) {
        max = remaining + already_applied;
        explain_max = '';
        if(already_applied)
            explain_max += 'Already applied ' + format_money(already_applied);
        if(remaining)
            explain_max += (explain_max.length ? '<br>+ a' : 'A') + 'vailable credit ' + format_money(remaining);
    }
    else {
        max = balance + already_applied;
        explain_max = '';
        if(already_applied)
            explain_max += 'Already applied ' + format_money(already_applied);
        if(balance)
            explain_max += (explain_max.length ? '<br>+ o' : 'O') + 'utstanding balance ' + format_money(balance);
    }
    if(!max)
        explain_max = "No maximum applicable";

    $('div.apply-payment div.maximum-display').html(format_money(max));

    if(!max)
        $('div.apply-payment div.option-max').addClass('disabled');
    else $('div.apply-payment div.option-max').removeClass('disabled');
}

$('#apply_payment').on('click', function(e) {
    if($('#uitable').hasClass('loading')) {
        notify("Please wait until the table has finished loading to apply payments.", true, 4000);
        return false;
    }
    var selected = uit.getSelected(1);
    if(!selected || selected.length == 0) {
        $('#select_all').trigger('click');
        selected = uit.getSelected(1);
    }
    show_apply_container(selected);
});

$(window).on('click', function(e) {
    if(!$(e.target).hasClass('apply-payment')) {
        if(!$(e.target).closest('div.apply-payment-container').length) {
            var apply_container = $('div.apply-payment-container');
            if(apply_container.is(':visible')) {
                apply_container.hide();
                e.preventDefault();
                return false;
            }
        }
    }
});

var applied_column = -1;
var paid_column = -1;
var balance_column = -1;

$('#uitable').on({
    'select-rows': function(e, selected) {
        $('#module_main footer button').prop('disabled', selected.length ? false : true);
        if(selected.length)
            selected = selected[0];
        update_totals();
    },
    'load-data': function(e, data, state) {
        if(data.count)
            $('.multiselect-container').removeClass('hidden');
        else $('.multiselect-container').addClass('hidden');
        if(typeof(data.ids) != 'undefined') {
            if(typeof(data.extras) != 'undefined') {
                old_invoices = invoices;
                invoices = { };
                for(var i in data.extras)
                    invoices[data.extras[i].id] = data.extras[i];
                update_totals();
                if(payment_id)
                    for(var i in invoices)
                        show_applied_for_invoice(invoices[i].id);
            }
            $('#select_all').prop('disabled', data.ids.length ? false : true);
        }

        var obj = {filter:$.extend({}, state.filter, uit_extra_data()), sort:state.sort, columns:state.columns };
        $('#uitable_export').attr('href', location.pathname + '?mod=accountants&act=exportbrowse&' + $.param(obj));

        var current_column = 0;
        $('#uitable .ui-table-head cell').each(function() {
            if($(this).attr('data-col') == 'payment_applied')
                applied_column = current_column;
            if($(this).attr('data-col') == 'balance')
                balance_column = current_column;
            if($(this).attr('data-col') == 'paid')
                paid_column = current_column;
            current_column++;
        });

        var cell = $('#uitable row cell[pos=' + applied_column + ']');
        cell.addClass('payment-applied');

        if(typeof(data.rows) != 'undefined' && data.rows)
            for(var i in data.rows)
                show_applied_for_invoice(data.rows[i].id);
    },
    'change-state': function() {
        $('.multiselect-container').addClass('hidden');
        set_filters(load_filters(get_hash()));
    },
    'reset-state': function() {
        set_filters({ });
        reset_advanced_filters();
    },
    'clear-filters': function() {
        set_filters({ });
        reset_advanced_filters();
    },
});

$('#table_filter').on('click', function(e) {
    if($('#table_filter_menu').is(':visible')) {
        $('#table_filter_menu').hide();
    }
    else {
        $('#table_filter_menu').show();
    }
});

$(window).on('mouseup', null, this, function(e) {
    if($('#table_filter_menu').is(':visible') && !$(e.target).data('filter') && $(e.target).attr('id') != 'table_filter')
        $('#table_filter_menu').hide();
});

function summarize_filters() {
    var html = '';
    var notfirst = 0;
    for(var i in FILTERS)
        if(typeof filters[i] != 'undefined' && filters[i])
                html += (notfirst++ ? ',&nbsp;&nbsp;' : '...') + "<div class='summarize-filter' data-filter='" + i + "'>" + FILTERS[i] + "&nbsp;</div><div class='remove-filter' data-filter='" + i + "'><i class='fa fa-remove'></i></div>";
    $('#table_filter_summary').html(html);
    $('.remove-filter').on('click', function(e) {
        $('.table-filter[data-filter="' + $(this).data('filter') + '"]').click();
    });
    $('.remove-filter').on({
        mouseenter: function() {
            $('.summarize-filter[data-filter="' + $(this).data('filter')  + '"]').addClass('removing');
        },
        mouseleave: function() {
            $('.summarize-filter[data-filter="' + $(this).data('filter')  + '"]').removeClass('removing');
        }
    });
}

function set_filters(new_filters) {
    filters = new_filters;
    $('.table-filter').each(function(e) {
        var filter = $(this).data('filter');
        if(typeof filters[filter] != 'undefined' && filters[filter])
            $(this).addClass('selected');
        else $(this).removeClass('selected');
    });

    var filters_active = 0;
    for(var i in filters)
        if(filters[i])
            filters_active = 1;
    if(filters_active) {
        $('#table_filter').addClass('active');
        summarize_filters();
        $('#table_filter_summary').show();
    }
    else {
        $('#table_filter').removeClass('active');
        $('#table_filter_summary').hide();
    }
}

var html = '';
for(var i in FILTERS)
    html += "<div class='table-filter" + (typeof filters[i] != 'undefined' && filters[i] ? ' selected' : '') + "' data-filter='" + i + "'>..." + FILTERS[i] + "</div>";
$('#table_filter_menu').html(html);

$('.table-filter').on('click', function(e) {
    e.preventDefault();
    var filter = $(this).data('filter');
    if(typeof filters[filter] != 'undefined' && filters[filter]) {
        $(this).removeClass('selected');
        delete filters[filter];
    }
    else {
        $(this).addClass('selected');
        filters[filter] = 1;
    }
    if(Object.keys(FILTERS).length == 1)
        $('#table_filter_menu').hide();
    uit.refresh();
});

if(Object.keys(FILTERS).length == 0)
    $('.table-filter-container').hide();

function sync_client_invoice_display() {
    var val = $('#client_id').val();
    if(val) {
        if(uit) {
            uit.refresh();
        }
        $('tab_content.sub-uitable').addClass('client');
        $('div.payment-applied-no-client').hide();
        $('div.payment-applied').show();
        $('#uitable').show();
        $('div.sub-header').show();
    }
    else {
        $('tab_content.sub-uitable').removeClass('client');;
        $('div.payment-applied-no-client').show();
        $('#uitable').hide();
        $('div.payment-applied').hide();
        $('div.sub-header').hide();
    }
}
sync_client_invoice_display();

$('#client_id').on('mousedown', function(e) {
    if($(this).hasClass('pseudo-disabled'))
        notify("Cannot change client once payment has been applied.", true);
});

$('#client_id').on('change', function() {
    sync_client_invoice_display();
});

$('div.apply-payment div.option-max').on({
    mouseover: function(e) {
        $('div.apply-payment div.explain').html(explain_max);
    },
    mouseout: function(e) {
        $('div.apply-payment div.explain').html('');
    }
});

$('div.apply-payment div.option-for').on({
    mouseover: function(e) {
        $('div.apply-payment div.explain').html(explain_for);
    },
    mouseout: function(e) {
        $('div.apply-payment div.explain').html('');
    }
});

$('input[type=submit]').on('click', function(e) {
    if(!$(this).hasClass('submitting')) {
        $(this).addClass('submitting');
        $(this).val('...submitting...');
        if($('body').hasClass('overapplied')) {
            e.preventDefault();
            notify("You cannot save this payment while it is over-applied.", true);
            return false;
        }
        var applied = { };
        for(var i in payment_application) {
            if(typeof(applied[payment_application[i].invoice_id]) == 'undefined')
                applied[payment_application[i].invoice_id] = { payment_id: payment_application[i].id, amount: payment_application[i].amount };
            else {
                if(!applied[payment_application[i].invoice_id].payment_id)
                    applied[payment_application[i].invoice_id].payment_id = payment_application[i].id;
                applied[payment_application[i].invoice_id].amount += payment_application[i].amount;
            }
        }
        $('input[name="amount"]').val(interpret_money($('input[name="amount"]').val()));
        $('input[name="application"]').val(stringify(applied));
    }
    else {
        e.preventDefault();
        notify("The payment is already being submitted.  Please wait.", true);
        return false;
    }
});

$('#select_all').on('click', function(e) {
    e.preventDefault();
    uit.select_all();
});

$('#select_none').on('click', function(e) {
    e.preventDefault();
    uit.select_none();
});

$('select[name="client_id"]').on('change', function() {
    if($(this).val())
        $('#viewclient').show().attr('href', '/admin/?mod=clients&id=' + $(this).val());
    else $('#viewclient').hide();
});

$('.datepicker').datepicker();

$('div.form_main select').on('change', function() {
    $('input[type="submit"]').addClass('flash');
});

$('div.form_main input, div.form_main textarea').on('keydown', function() {
    $('input[type="submit"]').addClass('flash');
});

$('button.delete-payment').on('click', function(e) {
    e.stopPropagation();
    if($(this).hasClass('disabled')) {
        notify($(this).attr('data-block') ? $(this).attr('data-block') : "You cannot delete this payment.", true);
    }
    else if(confirm("Are you sure you want to delete this payment?")) {
        $('input[name="delete"]').val(1);
        $('#module_main').submit();
    }
    return false;
});

if(ALLOW_SET) {
    if(payment_application.length) {
        $('button.delete-payment').addClass('disabled', true);
        $('button.delete-payment').attr('data-block', "You cannot delete a payment that has been applied to invoices.");
    }
}
else {
    $('button.delete-payment').addClass('disabled', true);
    $('button.delete-payment').attr('data-block', "You cannot delete a " + PAYMENT_METHOD + " payment.");
}
