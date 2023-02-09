<?php

/***** CODE SAMPLE: Part of an in-house accounting system designed to replace QuickBooks *****/


use ??\??Client;

class ClientInvoice extends DatedData
{
    public static $dbTable = 'client_invoice';
    public static $dbColumns = [ 'id', 'invoice_id', 'payment_id', 'type_id', 'workorder_id', 'batch_id', 'status', 'terms', 'customer_address', 'site_address', 'po', 'vendor_number', 'client_reference', 'scheduled_date', 'site_id', 'project_manager', 'project_manager_email', 'total_invoiced', 'total_paid', 'issue_date', 'tax', 'tax_details', 'note', 'log' ];
    public static $dbPrimary = 'id';

    public $id;
    public $invoice_id;  // note that invoice_id also depends on type_id - so there can be two invoices for the same work order with an invoice_id of 2, but with different type_ids (one a bill and one a credit memo)
    public $payment_id = 0;
    public $type_id = 0;
    public $workorder_id;
    public $batch_id;
    public $status;
    public $terms;
    public $customer_address;
    public $site_address;
    public $po;
    public $vendor_number;
    public $client_reference;
    public $scheduled_date;
    public $site_id;
    public $project_manager;
    public $project_manager_email;
    public $total_invoiced = 0;
    public $total_paid = 0;
    public $issue_date;
    public $tax = 0;
    public $tax_details;
    public $note;
    public $log;

    const TypeInvoice = 0;
    const TypeCreditMemo = 1;

    const StatusCreated = 0;
    const StatusIssued = 1;
    const StatusPartPaid = 2;
    const StatusPaid = 3;

    public function save($updateCache = false, $skip_status_promotions = false, $skip_payment_creation = false, $skip_tax = false) {
        $theUser = Session::user();

        if($this->type_id == self::TypeInvoice) {
            if(!$skip_status_promotions) {
                if($this->status == self::StatusPaid) {
                    if(round($this->total_paid, 2) < round($this->total_invoiced, 2) + round($this->tax, 2)) {
                        $this->status = round($this->total_paid, 2) > 0 ? self::StatusPartPaid : ($this->issued() ? self::StatusIssued : self::StatusCreated);
                        $this->log("Invoice reverted to " . $this->status() . (empty($theUser) ? '' : " by " . $theUser->name()) . ".", false);
                        $workorder = $this->workorder();
                        $workorder->client_invoice_status = round($this->total_paid, 2) > 0 ? Workorder::CLIENT_INVOICE_PART_PAID : Workorder::CLIENT_INVOICE_ISSUED;
                        $workorder->client_paid = 0;
                        $workorder->client_paid_date = '0000-00-00 00:00:00';
                        $workorder->save();
                    }
                }
                else {
                    $workorder = $this->workorder();
                    if(round($this->total_paid, 2) >= round($this->total_invoiced, 2) + round($this->tax, 2) && $this->status >= self::StatusIssued) {
                        $this->status = self::StatusPaid;
                        $this->log("Invoice set to Paid" . (empty($theUser) ? '' : " by " . $theUser->name()) . ".", false);

                        if($workorder->client_invoice_status < Workorder::CLIENT_INVOICE_PAID || !$workorder->client_paid || $workorder->client_paid_date == '0000-00-00 00:00:00') {
                            $workorder->client_invoice_status = Workorder::CLIENT_INVOICE_PAID;
                            $workorder->client_paid = 1;
                            $workorder->client_paid_date = $this->get_latest_payment_date();
                            $workorder->save();
                        }
                    }
                    else if(round($this->total_paid, 2) > 0 && $this->status < self::StatusPartPaid && $this->status >= self::StatusIssued) {
                        $this->status = self::StatusPartPaid;
                        $this->log("Invoice set to Partially-Paid" . (empty($theUser) ? '' : " by " . $theUser->name()) . ".", false);
                        if($workorder->client_invoice_status < Workorder::CLIENT_INVOICE_PART_PAID) {
                            $workorder->client_invoice_status = Workorder::CLIENT_INVOICE_PART_PAID;
                            $workorder->save();
                        }
                    }
                    else if(round($this->total_paid, 2) == 0 && $this->status >= self::StatusPartPaid) {
                        $this->status = $this->issued() ? self::StatusIssued : self::StatusCreated;
                        $this->log("Invoice reverted to " . $this->status() . (empty($theUser) ? '' : " by " . $theUser->name()) . ".", false);
                    }
                }
                if($this->status >= self::StatusIssued && $this->issue_date == '0000-00-00') {
                    $this->issue_date = date('Y-m-d H:i:s');
                    $this->log("Invoice was set to " . self::get_status_name($this->status) . " without being Issued; Issue Date automatically set to " . $this->issue_date . (empty($theUser) ? '' : " by " . $theUser->name()) . ".", false);
                }
            }
        }
        else {  // credit memo
            if($this->status == self::StatusIssued)
                $this->status = self::StatusPaid;
        }

        if(!$skip_tax && $this->status >= self::StatusIssued && $this->getOriginalValue('status') < self::StatusIssued) {
            $this->apply_tax(0, false, true);  // apply tax for real (commit to ??)
		}

        $rc = parent::save($updateCache);

        if(!$skip_payment_creation && $this->type_id == self::TypeCreditMemo && $this->status >= self::StatusIssued && empty($this->payment_id) && (round($this->total_invoiced, 2) + round($this->tax, 2)) < 0) {
            $payment_amount = abs(round($this->total_invoiced, 2)) + abs(round($this->tax, 2));
            $payment = new ClientPayment();
            $payment->payment_date = date('Y-m-d');
            $payment->payment_id = $this->invoice_id();
            $payment->client_id = $this->client_id();
            $payment->method = ClientPayment::PaymentCreditMemo;
            $payment->amount = $payment_amount;
            $payment->save();
            $payment->log("Credit Memo #{$this->id} converted to client payment");
            $this->log("Credit Memo converted to client payment #" . $payment->id, false);
            $this->payment_id = $payment->id;
            $this->save(false, false, true);
        }
        return $rc;
    }

    /*
     *  issue() will email an invoice to all Client and Project Invoice Recipients.
     *  It will also set the invoice to Issued if it has not already been.
     *
     */

    public function issue($recipients = 0, $format = Client::BILLING_PDF) {  // for individual PDF or Excel invoices - see ClientInvoiceBatch for multi-issued invoices
        if(empty($recipients) && $recipients !== false) {
            $recipients = [ ];
            foreach(ClientInvoiceRecipient::select()->where('client_id = ?', [ $this->client_id() ])->getAll() as $recipient)
                if(!empty($recipient->email))
                    $recipients[] = $recipient->email;
        }
        if($recipients !== false && !empty($_REQUEST['include_project_recipients']) && !empty($this->workorder()->project_id))
            foreach(ProjectInvoiceRecipient::select()->where('project_id = ?', [ $this->workorder()->project_id ])->getAll() as $recipient)
                if(!empty($recipient->email))
                    $recipients[] = $recipient->email;
        if($this->status < self::StatusIssued) {
            $this->status = self::StatusIssued;
            $this->log("Invoice set to Issued" . (empty($theUser) ? '' : " by " . $theUser->name()) . ".", false);
        }
        if(!$this->issued())
            $this->issue_date = date('Y-m-d H:i:s');
        $this->save();
        $workorder = $this->workorder();
        if($workorder->client_invoice_status < Workorder::CLIENT_INVOICE_ISSUED) {
            $workorder->client_invoice_status = Workorder::CLIENT_INVOICE_ISSUED;
            $workorder->save();
        }
        if(is_array($recipients) && count($recipients)) {
            $this->batch()->issue_invoices($recipients, $format, [ $this ]);
        }
    }

    /*
     *  resum_payments() for when a Director or admin goes power-mad and changes a task after invoicing.
     *
     */

    public function resum_payments($skip_save = false) {
        $total_paid = 0;
        foreach(ClientInvoicePayment::select()->where('invoice_id = ?', [ $this->id ])->getAll() as $payment) {
            $total_paid += (float) $payment->amount;
        }
        $this->total_paid = $total_paid;
        if(!$skip_save)
            $this->save();
        return $total_paid;
    }

    public function issued($include_time = false) {
        if(!empty($this->issue_date) && $this->issue_date != '0000-00-00')
            return $include_time ? $this->issue_date : substr($this->issue_date, 0, 10);
    }

    public function client() {
        return Client::getById($this->batch()->client_id);
    }

    public function client_id() {
        return $this->batch()->client_id;
    }

    public function batch() {
        return ClientInvoiceBatch::select()->where('id = ?', [ $this->batch_id ])->getOne();
    }

    public function workorder() {
        return Workorder::select()->where('id = ?', [ $this->workorder_id ])->getOne();
    }

    public function invoice_id($abbreviate_initial = true) {
        return $this->workorder_id . ($this->invoice_id == 1 && $abbreviate_initial ? '' : '-' . $this->invoice_id) . ($this->credit_memo() ? '-CM' : '');
    }

    public function credit_memo() {
        return $this->type_id == self::TypeCreditMemo;
    }

    public function name($capitalized = true) {
        return $this->credit_memo() ? ($capitalized ? "Credit Memo" : "credit memo") : ($capitalized ? "Invoice" : "invoice");
    }

    public function status() {
        return self::get_status_name($this->status);
    }

    public static function get_status_name($status_id) {
        switch($status_id) {
            case self::StatusCreated:
                return 'Created';
            case self::StatusIssued:
                return 'Issued';
            case self::StatusPartPaid:
                return 'Partially-Paid';
            case self::StatusPaid:
                return 'Paid';
            default:
                return 'Unknown';
        }
    }

    public function get_latest_payment_date() {
        $latest_payment_date = 0;
        foreach(ClientInvoicePayment::select()->where('invoice_id = ?', $this->id)->getAll() as $invoice_payment) {
            $payment = ClientPayment::getById($invoice_payment->payment_id);
            $payment_date = strtotime($payment->payment_date);
            if(!$latest_payment_date || $payment_date > $latest_payment_date)
                $latest_payment_date = $payment_date;
        }
        return date('Y-m-d', $latest_payment_date);
    }

    public function get_line_items($attach_tax = true, $type = -1) {
        if(($workorder = Workorder::getById($this->workorder_id)) && $workorder->found) {
            $line_items = $workorder->get_client_line_items(is_null($this->id) ? 0 : $this->id, $type);
            if($attach_tax && !empty($this->tax_details)) {
/*  disabled for now
                $transaction = json_decode($this->tax_details);
                if(!empty($transaction->lines)) {
                    foreach($transaction->lines as $line) {
                        foreach($line_items as $line_item_index => $line_item) {
                            if($line_item->id == $line->itemCode)
                                $line_items[$line_item_index]->tax = $line->tax;
                        }
                    }
                }
*/
            }
            return $line_items;
        }
    }

    /*
     *  apply_tax() runs the line items through ?? (see environment variables for credentials).
     *
     */

    public function apply_tax($items = 0, $preview_only = false, $actually_tax = false) {
        if(!empty($this->tax_details)) {
            $transaction = json_decode($this->tax_details);
            $this->transaction = $transaction;
            return;
        }

        if(($workorder = Workorder::getById($this->workorder_id)) && $workorder->found) {
            if($site = $workorder->site()) {
                $client_id = $site->client_id;
                $client = $site->client();
                if($address = $site->address()) {
                    $?? = new ??\??('??', '1.0', env('??'), env('??'));
                    $??->withSecurity(env('??'), env('??'));

                    $type = ??\DocumentType::C_SALESORDER;
                    if($actually_tax)
                        $type = ??\DocumentType::C_SALESINVOICE;
                    $tb = new ??\TransactionBuilder($??, "DEFAULT", $type, $client_id);
                    $tb->withAddress('SingleLocation', $address->address1, !empty($address->address2) ? $address->address2 : null, null, $address->city, $address->state(), $address->zip, $address->country == 1 ? 'US' : 'CA');
                    $tb->withReferenceCode($workorder->id);
                    $doc_code_rider = '';
                    if(env('BETA_SITE'))
                        $doc_code_rider .= substr(env('ENV_NAME'), 0, 1);
                    if(env('RUN_LOCAL'))
                        $doc_code_rider .= 'L';
                    if(!empty($doc_code_rider))
                        $doc_code_rider = '-' . strtoupper($doc_code_rider);
                    $tb->withTransactionCode($this->invoice_id() . $doc_code_rider);
                    $tb->withPurchaseOrderNo(substr($client->company, 0, 42));
                    if(empty($items))
                        $items = $this->get_line_items();
                    $tasks = empty($items['tasks']) ? [ ] : $items['tasks'];
                    foreach($tasks as $task) {
                        $tax_code = "P0000000";
                        if($task_tax_code = $task->tax_code())
                            $tax_code = $task_tax_code;
                        $item_code = null;
                        $item_code = $task->id;
                        $tb->withLine(round(((float) $task->client_rate) * ((float) $task->client_qty), 2), (float) $task->client_qty, $item_code, $tax_code);
                        $tb->withLineDescription($task->description);
                        $task->tax_code = $tax_code;
                    }
                    if($actually_tax)
                        $tb->withCommit();

                    $transaction = $tb->create();

                    if(!empty($transaction->totalTax))
                        $this->tax = round($transaction->totalTax, 2);

                    if($actually_tax) {
                        $this->tax_details = json_encode($transaction);
                        $this->saveSilently();
                    }

                    // temporary, to show debug information on preview
                    if(!empty($transaction->lines)) {
                        foreach($transaction->lines as $line) {
                            foreach($tasks as $task) {
                                if($task->id == $line->itemCode) {
                                    if($actually_tax) {
                                        $task->tax = round($line->tax, 2);
                                        $task->saveSilently();
                                    }
                                }
                            }
                        }
                    }
                    $this->transaction = $transaction;
                }
            }
        }
    }

    public function total_invoiced($from_tasks = true) {
        if($from_tasks) {
            $total_invoiced = 0;
            $items = $this->get_line_items();
            if(!empty($items['tasks'])) {
                foreach($items['tasks'] as $task) {
                    $total_invoiced += round(($task->client_qty * $task->client_rate) + $task->tax, 2);
                }
            }
            if(!empty($items['adjustments'])) {
                foreach($items['adjustments'] as $adjustment) {
                    $total_invoiced += round($adjustment->calculated_adjustment, 2);
                }
            }
            return round($total_invoiced, 2);
        }
        return round($this->total_invoiced, 2);
    }

    public function total_amount($thousands_separator = false, $strip_negative = false) {
        $amount = round(round($this->total_invoiced, 2) + round($this->tax, 2), 2);
        if($this->credit_memo()) {
            if($strip_negative)
                $amount = abs($amount);
            if($thousands_separator)
                return number_format($amount, 2);
            return format_currency('%i', $amount);
        }
        if($thousands_separator)
            return number_format($amount, 2);
        return format_currency('%i', $amount);
    }

    public function total_paid($thousands_separator = false) {
        if(!$this->credit_memo()) {
            if($thousands_separator)
                return number_format(round($this->total_paid, 2), 2);
            return format_currency('%i', round($this->total_paid, 2));
        }
    }

    public function total_due($thousands_separator = false) {
        if(!$this->credit_memo()) {
            $amount = round(round($this->total_invoiced, 2) + round($this->tax, 2), 2);
            $paid = round($this->total_paid, 2);
            if($thousands_separator)
                return number_format(round($amount - $paid, 2), 2);
            return format_currency('%i', round($amount - $paid, 2));
        }
    }

    public function due_date() {
        if($this->credit_memo() || empty($this->issue_date) || $this->issue_date == '0000-00-00')
            return '--';
        return date('Y-m-d', strtotime($this->issue_date) + ($this->terms * 86400));
    }

    public function html($format_pdf = false) {
        $this->apply_tax(0, true);  // either fetch or repopulate from saved details
		$root = config('site_Root');
        $theData = $this;
		ob_start();
		include "$root/admin/module/accountants/template/invoice_html.php";
		$html = ob_get_clean();
        return $html;
    }

    public function pdf() {
		$root = config('site_Root');
		$pdf_filename = 'tls_invoice_' . $this->invoice_id() . '.pdf';
		$pdf_file = sys_get_temp_dir() . '/' . $pdf_filename;
		$dompdf = new Dompdf\Dompdf();
		$dompdf->setBasePath($root);
		$dompdf->loadHtml($this->html(true));
		@$dompdf->render();  // suppressing "non-numeric value encountered" errors
        $output = $dompdf->output();
		file_put_contents($pdf_file, $output);
        return $pdf_file;
    }

    public function log($str = 0, $save = true) {
        if($str) {
            if(empty($this->log))
                $this->log = '';
            $this->log .= "[" . date('Y-m-d H:i:s T') . "] $str\n";
            if($save)
                $this->save();
        }
        return $this->log;
    }

    public static function aging_report() {
        $rep_id = 0;
        if(!empty($_REQUEST['rep_id']))
            $rep_id = (int) $_REQUEST['rep_id'];

        $historic_view = 0;
        if(!empty($_REQUEST['historic_view']))
            $historic_view = date('Y-m-d', strtotime($_REQUEST['historic_view']));

        $clients = [ ];
        $query = ClientInvoice::select()->join('inner', 'client_invoice_batch', 'client_invoice_batch.id = client_invoice.batch_id')->join('inner', 'client', 'client_invoice_batch.client_id = client.client_id')->where('client_invoice.issue_date IS NOT NULL AND client_invoice.issue_date <> \'0000-00-00\'')->sort('client.company, client_invoice.workorder_id DESC, client_invoice.invoice_id DESC');

        if(!empty($_REQUEST['client_id']))
            $query->andWhere('client.client_id = ?', $_REQUEST['client_id']);
        if(!empty($_REQUEST['invoiced_start']))
            $query->andWhere('client_invoice.issue_date >= ?', $_REQUEST['invoiced_start']);
        if(!empty($_REQUEST['invoiced_end']))
            $query->andWhere('client_invoice.issue_date <= ?', $_REQUEST['invoiced_end']);
        $query->join('inner', 'users', 'client.rep_id = rep_user.user_id', 'rep_user');
        if(!empty($_REQUEST['rep_id']))
            $query->andWhere('client.rep_id = ?', $_REQUEST['rep_id']);

        $additional_columns = '';
        if($historic_view) {
            $query->andWhere("DATE(client_invoice.created_date) < ?", $historic_view);
            $query->join('left', 'client_invoice_payment', "client_invoice_payment.deleted = 0 AND client_invoice_payment.invoice_id = client_invoice.id AND client_invoice_payment.created_date < '$historic_view'");
            $additional_columns .= (empty($additional_columns) ? '' : ', ') . "ROUND(SUM(client_invoice_payment.amount), 2) AS payments_amount";
            $query->having('ROUND(invoiced - (CASE WHEN payments_amount IS NULL THEN 0 ELSE payments_amount END), 2)<> 0');
        }
        else {
            $query->andWhere('ROUND((client_invoice.total_invoiced + client_invoice.tax) - client_invoice.total_paid, 2) <> 0');  // only with a balance
            $query->andWhere('ROUND((client_invoice.total_invoiced + client_invoice.tax) - client_invoice.total_paid, 2) > 0');
        }
        $query->group('client_invoice.id');

        foreach($query->getColumns("?? AS $invoice) {
             if(empty($clients[$invoice->company]))
                $clients[$invoice->company] = [
                    'client_id' => $invoice->client_id,
                    'company' => $invoice->company,
                    'sales_rep' => $invoice->sales_rep,
                    'invoices' => [ ],
                    'credit_limit' => $invoice->credit_limit
                ];
            $clients[$invoice->company]['invoices'][] = [
                'id' => $invoice->id,
                'po' => $invoice->po,
                'site_id' => $invoice->site_id,
                'invoice_id' => $invoice->invoice_id,
                'invoiced' => $invoice->invoiced,
                'paid' => $invoice->paid,
                'balance' => $invoice->balance,
                'issue_date' => date('m/d/Y', strtotime($invoice->issue_date)),
                'terms' => $invoice->terms,
                'due_date' => date('m/d/Y', strtotime($invoice->due_date)),
                'days_overdue' => $invoice->days_overdue,
            ];
        }

        $query = ClientPayment::select();
        if($historic_view)
            $query->andWhere("DATE(client_payment.created_date) < ?", $historic_view);
        $query->join('inner', 'client', 'client_payment.client_id = client.client_id');
        if(!empty($_REQUEST['client_id']))
            $query->andWhere('client.client_id = ?', $_REQUEST['client_id']);
        if(!empty($_REQUEST['invoiced_start']))
            $query->andWhere('client_payment.payment_date >= ?', $_REQUEST['invoiced_start']);
        if(!empty($_REQUEST['invoiced_end']))
            $query->andWhere('client_payment.payment_date <= ?', $_REQUEST['invoiced_end']);

        $query->where('(CASE WHEN total_payment.total IS NULL THEN ROUND(client_payment.amount, 2) ELSE ROUND(client_payment.amount - total_payment.total, 2) END) <> 0');
        $query->group('client_payment.id');

        $query->join('outer', '(SELECT payment_id, ROUND(SUM(amount), 2) AS total FROM client_invoice_payment WHERE deleted = 0 ' . ($historic_view ? "AND DATE(client_invoice_payment.created_date) < '$historic_view' " : '') . 'GROUP BY payment_id)', 'total_payment.payment_id = client_payment.id', 'total_payment');
        $query->join('inner', 'users', 'client.rep_id = rep_user.user_id', 'rep_user');
        if(!empty($_REQUEST['rep_id']))
            $query->andWhere('client.rep_id = ?', $_REQUEST['rep_id']);
        foreach($query->getColumns("?? as $payment) {
             if(empty($clients[$payment->company])) {
                $clients[$payment->company] = [
                    'client_id' => $payment->client_id,
                    'company' => $payment->company,
                    'sales_rep' => $invoice->sales_rep,
                    'payments' => [ ],
                    'credit_limit' => $payment->credit_limit
                ];
            }
            else if(empty($clients[$payment->company]['payments'])) {
                $clients[$payment->company]['payments'] = [ ];
            }
            $clients[$payment->company]['payments'][] = [
                'id' => $payment->id,
                'payment_id' => $payment->payment_id,
                'method' => ClientPayment::method_name($payment->method),
                'amount' => $payment->amount,
                'credit' => $payment->credit,
                'payment_date' => $payment->payment_date,
                'days_since' => $payment->days_since,
            ];
        }

        function sort_by_client_name($a, $b) {
            $a = trim(strtolower($a['company']));
            $b = trim(strtolower($b['company']));
            if(substr($a, 0, 4) == 'the ')
                $a = substr($a, 5);
            else if(substr($a, 0, 2) == 'a ')
                $a = substr($a, 3);
            if(substr($b, 0, 4) == 'the ')
                $b = substr($b, 5);
            else if(substr($b, 0, 2) == 'a ')
                $b = substr($b, 3);
            if($a < $b)
                return -1;
            if($a > $b)
                return 1;
        }
        usort($clients, 'sort_by_client_name');
        return $clients;
    }

    public function refresh_from_workorder($workorder = 0) {
        if(!$workorder)
            $workorder = $this->workorder();
        $client = $this->client();
        $client_address = $client->address();
        $site = $workorder->site();
        $site_address = $site->address();
        $pm = $workorder->projectManager();
        $this->customer_address = "{$client->company}\n";
        $this->customer_address .= "{$client_address->address1}\n";
        if(!empty($client_address->address2))
            $this->customer_address .= "{$client_address->address2}\n";
        $this->customer_address .= $client_address->city . ($client_address->city && $client_address->state() ? ', ' : '') . $client_address->state() . ($client_address->zip != '00000' ? ' ' . $client_address->zip : '');  // some clients have blank addresses
        if($client_address->country != 1)
            $this->customer_address .= ' ' . $client_address->country() . "\n";
        $this->site_address = "{$site->company}\n";
        $this->site_address .= "{$site_address->address1}\n";
        if(!empty($site_address->address2))
            $this->site_address .= "{$site_address->address2}\n";
        $this->site_address .= "{$site_address->city}, " . $site_address->state() . " {$site_address->zip}\n";
        if($site_address->country != 1)
            $this->site_address .= $site_address->country() . "\n";
        $this->po = $workorder->po;
        $this->vendor_number = $client->vendor_number;
        $this->client_reference = $workorder->client_reference;
        $this->site_id = $site->siteid;
        $this->project_manager = $pm->getName();
        $this->project_manager_email = $pm->email;
        $this->log("Invoice refreshed by " . Session::user()->name() . ".", false);
        $this->save();
    }
}
